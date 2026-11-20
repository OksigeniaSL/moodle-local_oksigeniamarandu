#!/usr/bin/env python3
# This file is part of Oksigenia Marandu, a plugin for Moodle - https://moodle.org/
#
# Oksigenia Marandu is free software: you can redistribute it and/or modify it under the terms of the GNU General
# Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any
# later version. It is distributed WITHOUT ANY WARRANTY; see <https://www.gnu.org/licenses/>.
#
# Copyright 2026 Oksigenia SL <dev@oksigenia.cc>
"""Reads the chats of WhatsApp Business (or WhatsApp) from a local backup of an iPhone, not encrypted, as
`idevicebackup2 backup --full <dir>` leaves it, and writes them as import_history.rb takes them: one chat per contact,
with its phone, its messages at their dates and its attachments in a folder beside the file.

Only the chats with one person: groups, statuses and broadcast lists stay out. The chats of WhatsApp go by LID
(<n>@lid) and not by phone: the phone comes from the contacts of the app, from the pairs of LID.sqlite, from older
messages of the same chat that still had the phone, or from a CSV the centre writes (lid,phone). The chats left without
a phone are listed, with what is known of them, so that they can be put in that CSV.

Nothing here writes to the backup. Standard library only.

    python3 whatsapp_backup.py BACKUP_DIR OUT_DIR [--app business|whatsapp] [--map lids.csv] [--no-media]

OUT_DIR gets history.json and media/. Then, in the Chatwoot container:
    bundle exec rails runner import_history.rb ACCOUNT_ID INBOX_ID OUT_DIR/history.json
"""
import argparse
import csv
import json
import os
import re
import shutil
import sqlite3
import sys
import tempfile

DOMAINS = {
    'business': 'AppDomainGroup-group.net.whatsapp.WhatsAppSMB.shared',
    'whatsapp': 'AppDomainGroup-group.net.whatsapp.WhatsApp.shared',
}
APPLE_EPOCH = 978307200  # 1 January 2001, the start of the dates of the app.
# What the app keeps as a message but nobody wrote: changes of the chat, security codes, calls without text…
SYSTEM_TYPES = {6, 10}
# The kind of an attachment by its type in the app; anything else with a file goes as a file.
MEDIA_TYPES = {1: 'image', 2: 'video', 3: 'audio', 8: 'file', 15: 'image'}
MIME = {'.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.webp': 'image/webp', '.gif': 'image/gif',
        '.heic': 'image/heic', '.mp4': 'video/mp4', '.mov': 'video/quicktime', '.opus': 'audio/ogg',
        '.ogg': 'audio/ogg', '.m4a': 'audio/mp4', '.mp3': 'audio/mpeg', '.aac': 'audio/aac', '.pdf': 'application/pdf',
        '.doc': 'application/msword', '.docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        '.xls': 'application/vnd.ms-excel', '.xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        '.txt': 'text/plain', '.vcf': 'text/vcard'}


def digits(text):
    return re.sub(r'\D', '', str(text or ''))


def jid_kind(jid):
    jid = str(jid or '')
    if jid.endswith('@s.whatsapp.net'):
        return 'phone'
    if jid.endswith('@lid'):
        return 'lid'
    return 'other'


class Backup:
    """The files of one app in the backup, by their path inside the app."""

    def __init__(self, root, domain):
        self.root = root
        manifest = os.path.join(root, 'Manifest.db')
        if not os.path.exists(manifest):
            sys.exit(f'No Manifest.db in {root}: is it a backup of idevicebackup2, not encrypted?')
        db = sqlite3.connect(f'file:{manifest}?mode=ro', uri=True)
        self.files = {path: file_id for file_id, path in db.execute(
            'SELECT fileID, relativePath FROM Files WHERE domain = ? AND flags = 1', (domain,))}
        db.close()
        if not self.files:
            sys.exit(f'The backup has nothing of {domain}.')
        self.tmp = tempfile.mkdtemp(prefix='marandu-whatsapp-')

    def path(self, relative):
        file_id = self.files.get(relative)
        if not file_id:
            return None
        found = os.path.join(self.root, file_id[:2], file_id)
        return found if os.path.exists(found) else None

    def database(self, name):
        """A database of the app, copied with its journal so that what is in the -wal counts, opened read-only."""
        main = self.path(name)
        if not main:
            return None
        # Each in a folder of its own: two databases of the app can have the same name.
        folder = os.path.join(self.tmp, str(len(os.listdir(self.tmp))))
        os.makedirs(folder)
        for suffix in ('', '-wal', '-shm'):
            source = main if suffix == '' else self.path(name + suffix)
            if source:
                shutil.copyfile(source, os.path.join(folder, os.path.basename(name) + suffix))
        db = sqlite3.connect(os.path.join(folder, os.path.basename(name)))
        try:
            db.execute('SELECT count(*) FROM sqlite_master').fetchone()
        except sqlite3.DatabaseError:
            db.close()
            return None
        return db

    def media(self, local):
        """The file of an attachment by the path the app keeps (relative to Message/ or not)."""
        local = str(local or '').lstrip('/')
        for candidate in (local, 'Message/' + local, 'Message/Media/' + local):
            found = self.path(candidate)
            if found:
                return found
        return None

    def close(self):
        shutil.rmtree(self.tmp, ignore_errors=True)


def columns(db, table):
    try:
        return [row[1] for row in db.execute(f'PRAGMA table_info({table})')]
    except sqlite3.DatabaseError:
        return []


LID = re.compile(r'^\d{5,20}(@lid)?$')
PHONE = re.compile(r'^\+?\d{8,15}(@s\.whatsapp\.net)?$')


def pairs_in(db, label, found):
    """Any table of a database with a column of LIDs beside a column of phones: the versions of the app keep them in
    different places (LID.sqlite, the contacts of the business, the metadata of the chats…)."""
    for (table,) in db.execute("SELECT name FROM sqlite_master WHERE type = 'table'"):
        names = columns(db, table)
        lidcols = [c for c in names if 'lid' in c.lower() and 'hash' not in c.lower()]
        phonecols = [c for c in names if c not in lidcols and any(w in c.lower() for w in ('phone', 'pn', 'jid', 'number'))
                     and 'hash' not in c.lower()]
        for lidcol in lidcols:
            for phonecol in phonecols:
                try:
                    rows = db.execute(f'SELECT "{lidcol}", "{phonecol}" FROM "{table}" '
                                      f'WHERE "{lidcol}" IS NOT NULL AND "{phonecol}" IS NOT NULL').fetchall()
                except sqlite3.DatabaseError:
                    continue
                for left, right in rows:
                    left, right = str(left).strip(), str(right).strip()
                    if LID.match(left) and PHONE.match(right) and not right.endswith('@lid'):
                        key = left if left.endswith('@lid') else left + '@lid'
                        found.setdefault(key, (digits(right.split('@')[0]), f'{label}.{table}'))


def lid_phones(contacts, lids, chats, others):
    """The phone of each LID, from every source there is, the most reliable first."""
    found = {}
    if contacts and 'ZLID' in columns(contacts, 'ZWAADDRESSBOOKCONTACT'):
        for lid, whatsappid, phone in contacts.execute(
                'SELECT ZLID, ZWHATSAPPID, ZPHONENUMBER FROM ZWAADDRESSBOOKCONTACT WHERE ZLID IS NOT NULL'):
            number = digits(whatsappid) or digits(phone)
            if number:
                found.setdefault(str(lid), (number, 'contacts'))
    # LID.sqlite and the other databases of the app: tables of pairs whose columns change with the versions.
    if lids:
        pairs_in(lids, 'LID', found)
    for label, db in others:
        pairs_in(db, label, found)
    # Older messages of the same chat, sent before the chat moved to its LID, still have the phone.
    for session, old in chats.execute(
            "SELECT m.ZCHATSESSION, m.ZFROMJID FROM ZWAMESSAGE m WHERE m.ZFROMJID LIKE '%@s.whatsapp.net' "
            "UNION SELECT m.ZCHATSESSION, m.ZTOJID FROM ZWAMESSAGE m WHERE m.ZTOJID LIKE '%@s.whatsapp.net'"):
        row = chats.execute('SELECT ZCONTACTJID FROM ZWACHATSESSION WHERE Z_PK = ?', (session,)).fetchone()
        if row and jid_kind(row[0]) == 'lid':
            found.setdefault(row[0], (digits(old.split('@')[0]), 'older messages'))
    return found


def read(backup_dir, app, mapping, with_media, out_dir):
    backup = Backup(backup_dir, DOMAINS[app])
    try:
        chats = backup.database('ChatStorage.sqlite')
        if not chats:
            sys.exit('The backup has no ChatStorage.sqlite of the app.')
        contacts = backup.database('ContactsV2.sqlite')
        lids = backup.database('LID.sqlite')
        others = []
        for relative in sorted(backup.files):
            name = os.path.basename(relative)
            if (relative.endswith('.sqlite') or relative.endswith('.db')) and \
                    name not in ('ChatStorage.sqlite', 'ContactsV2.sqlite', 'LID.sqlite') and not relative.startswith('Message/'):
                db = backup.database(relative)
                if db:
                    others.append((os.path.splitext(name)[0], db))
        known = lid_phones(contacts, lids, chats, others)
        names = {}
        if contacts:
            for lid, full, given in contacts.execute(
                    'SELECT ZLID, ZFULLNAME, ZGIVENNAME FROM ZWAADDRESSBOOKCONTACT WHERE ZLID IS NOT NULL'):
                names[str(lid)] = (full or given or '').strip()
        pushnames = dict(chats.execute('SELECT ZJID, ZPUSHNAME FROM ZWAPROFILEPUSHNAME'))
        media_cols = columns(chats, 'ZWAMEDIAITEM')
        threads, missing, counts = [], [], {'chats': 0, 'messages': 0, 'attachments': 0, 'files missing': 0}
        os.makedirs(os.path.join(out_dir, 'media'), exist_ok=True) if with_media else None
        sessions = chats.execute(
            'SELECT Z_PK, ZCONTACTJID, ZPARTNERNAME FROM ZWACHATSESSION WHERE ZSESSIONTYPE = 0 AND ZCONTACTJID IS NOT NULL')
        for pk, jid, partner in sessions.fetchall():
            if jid_kind(jid) == 'other':
                # Groups, statuses (@status, @lid.status) and broadcast lists.
                continue
            if jid_kind(jid) == 'phone':
                phone, source = digits(jid.split('@')[0]), 'chat'
            else:
                phone, source = mapping.get(jid) or mapping.get(jid.split('@')[0]) or known.get(jid, ('', ''))
                source = 'map' if (jid in mapping or jid.split('@')[0] in mapping) else source
            name = (names.get(jid) or partner or pushnames.get(jid) or '').strip()
            rows = chats.execute(
                'SELECT Z_PK, ZSTANZAID, ZMESSAGEDATE, ZISFROMME, ZMESSAGETYPE, ZTEXT, ZMEDIAITEM, ZMESSAGESTATUS '
                'FROM ZWAMESSAGE WHERE ZCHATSESSION = ? ORDER BY ZMESSAGEDATE, Z_PK', (pk,)).fetchall()
            messages = []
            for mpk, stanza, date, mine, kind, text, mediaitem, status in rows:
                if kind in SYSTEM_TYPES or date is None:
                    continue
                message = {'id': stanza or f'ios-{mpk}', 'at': int(float(date)) + APPLE_EPOCH, 'from_me': bool(mine),
                           'type': 'text', 'text': (text or '').strip(), 'status': 'read' if mine else ''}
                if mediaitem:
                    item = chats.execute(
                        f'SELECT ZMEDIALOCALPATH, ZTITLE{", ZVCARDSTRING" if "ZVCARDSTRING" in media_cols else ", NULL"} '
                        'FROM ZWAMEDIAITEM WHERE Z_PK = ?', (mediaitem,)).fetchone()
                    if item:
                        local, title, vcard = item
                        if vcard and not message['text']:
                            message['text'] = vcard.strip()
                        if title and not message['text']:
                            message['text'] = title.strip()
                        source_file = backup.media(local) if local else None
                        if local and not source_file:
                            counts['files missing'] += 1
                        if source_file and with_media:
                            ext = os.path.splitext(local)[1].lower()
                            stored = re.sub(r'[^A-Za-z0-9._-]', '_', f'{message["id"]}{ext}')
                            shutil.copyfile(source_file, os.path.join(out_dir, 'media', stored))
                            message['type'] = MEDIA_TYPES.get(kind, 'file')
                            message['file'] = 'media/' + stored
                            message['filename'] = (title or os.path.basename(local)).strip()
                            message['mime'] = MIME.get(ext, 'application/octet-stream')
                            counts['attachments'] += 1
                if not message['text'] and 'file' not in message:
                    continue
                messages.append(message)
            if not messages:
                continue
            if not phone:
                missing.append({'lid': jid, 'name': name, 'pushname': pushnames.get(jid, ''), 'messages': len(messages),
                                'first': messages[0]['at'], 'last': messages[-1]['at']})
                continue
            counts['chats'] += 1
            counts['messages'] += len(messages)
            threads.append({'phone': phone, 'name': name, 'source': source, 'messages': messages})
        return {'business': '', 'contacts': [{'phone': t['phone'], 'name': t['name']} for t in threads],
                'threads': threads}, missing, counts
    finally:
        backup.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('backup')
    parser.add_argument('out')
    parser.add_argument('--app', choices=sorted(DOMAINS), default='business')
    parser.add_argument('--map', help='CSV with lid,phone for the chats without a phone')
    parser.add_argument('--no-media', action='store_true', help='Leave the attachments out')
    args = parser.parse_args()
    mapping = {}
    if args.map:
        with open(args.map, newline='', encoding='utf-8') as handle:
            for row in csv.reader(handle):
                if len(row) >= 2 and digits(row[1]):
                    mapping[row[0].strip()] = (digits(row[1]), 'map')
    os.makedirs(args.out, exist_ok=True)
    data, missing, counts = read(args.backup, args.app, mapping, not args.no_media, args.out)
    with open(os.path.join(args.out, 'history.json'), 'w', encoding='utf-8') as handle:
        json.dump(data, handle, ensure_ascii=False)
    with open(os.path.join(args.out, 'without-phone.csv'), 'w', newline='', encoding='utf-8') as handle:
        writer = csv.writer(handle)
        writer.writerow(['lid', 'phone', 'name', 'pushname', 'messages', 'first', 'last'])
        for one in missing:
            writer.writerow([one['lid'], '', one['name'], one['pushname'], one['messages'], one['first'], one['last']])
    sources = {}
    for thread in data['threads']:
        sources[thread['source']] = sources.get(thread['source'], 0) + 1
    print(f"Chats: {counts['chats']} ({', '.join(f'{v} by {k}' for k, v in sorted(sources.items()))}); "
          f"messages: {counts['messages']}; attachments: {counts['attachments']}, "
          f"{counts['files missing']} not in the backup; without a phone: {len(missing)} "
          f"(in without-phone.csv: fill in the phone and pass it with --map).")


if __name__ == '__main__':
    main()

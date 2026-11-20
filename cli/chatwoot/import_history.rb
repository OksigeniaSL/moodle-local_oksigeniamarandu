# This file is part of Oksigenia Marandu, a plugin for Moodle - https://moodle.org/
#
# Oksigenia Marandu is free software: you can redistribute it and/or modify it under the terms of the GNU General
# Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any
# later version. It is distributed WITHOUT ANY WARRANTY; see <https://www.gnu.org/licenses/>.
#
# Copyright 2026 Oksigenia SL <dev@oksigenia.cc>
#
# Brings into an inbox of Chatwoot the chats a WhatsApp number had in the app of WhatsApp Business: those Meta sends
# when the number joins the API too (coexistence, cli/history.php --export), or those of a backup of the phone
# (whatsapp_backup.py). Each chat becomes a resolved conversation of its contact, one per contact, with every message
# at its own date and the attachments the file brings (paths relative to it). The rows are written directly: no
# webhook, no bot, no automation and nothing is sent to WhatsApp. A message already in the inbox (the same id of
# WhatsApp) is not written again, and the new ones of a chat go to the conversation it already brought, so it can run
# again with a newer file. Messages written from the app are outgoing and marked as echoes, as Chatwoot marks those
# that come live: they count as answered by a person.
#
# Inside the Chatwoot container:
#   bundle exec rails runner /path/import_history.rb ACCOUNT_ID INBOX_ID /path/history.json [--dry-run]

account_id, inbox_id, path = ARGV[0].to_i, ARGV[1].to_i, ARGV[2].to_s
dry = ARGV.include?('--dry-run')
abort 'Usage: rails runner import_history.rb ACCOUNT_ID INBOX_ID FILE [--dry-run]' if account_id <= 0 || inbox_id <= 0 || path.empty?

data = JSON.parse(File.read(path))
base = File.dirname(File.expand_path(path))
kinds = { 'image' => :image, 'audio' => :audio, 'video' => :video, 'file' => :file }
imported = %w[whatsapp_business_app whatsapp_backup]
account = Account.find(account_id)
inbox = account.inboxes.find(inbox_id)
whatsapp = inbox.channel_type == 'Channel::Whatsapp'
statuses = { 'sent' => 0, 'delivered' => 1, 'read' => 2, 'played' => 2, 'error' => 3 }
counts = Hash.new(0)

ActiveRecord::Base.transaction do
  (data['threads'] || []).each do |thread|
    phone = thread['phone'].to_s.gsub(/\D/, '')
    messages = (thread['messages'] || []).select { |m| m['id'].to_s != '' && m['at'].to_i.positive? }
    next counts[:empty] += 1 if phone.empty? || messages.empty?

    known = Message.where(account_id: account.id, inbox_id: inbox.id, source_id: messages.map { |m| m['id'] }).pluck(:source_id)
    messages = messages.reject { |m| known.include?(m['id']) }
    next counts[:already] += 1 if messages.empty?

    counts[:conversations] += 1
    counts[:messages] += messages.size
    counts[:attachments] += messages.count { |m| m['file'].to_s != '' }
    next if dry

    now = Time.current
    contact = account.contacts.find_by(phone_number: "+#{phone}")
    unless contact
      name = thread['name'].to_s.strip
      contact_id = Contact.insert!({ account_id: account.id, name: name.empty? ? "+#{phone}" : name,
                                     phone_number: "+#{phone}", created_at: now, updated_at: now },
                                   returning: %w[id]).first['id']
      contact = Contact.find(contact_id)
      counts[:contacts] += 1
    end
    contact_inbox = ContactInbox.find_by(contact_id: contact.id, inbox_id: inbox.id) ||
                    (whatsapp && ContactInbox.find_by(inbox_id: inbox.id, source_id: phone))
    unless contact_inbox
      contact_inbox_id = ContactInbox.insert!({ contact_id: contact.id, inbox_id: inbox.id,
                                                source_id: whatsapp ? phone : SecureRandom.uuid,
                                                created_at: now, updated_at: now }, returning: %w[id]).first['id']
      contact_inbox = ContactInbox.find(contact_inbox_id)
    end

    first = Time.zone.at(messages.first['at'].to_i)
    last = Time.zone.at(messages.last['at'].to_i)
    # The chat brought before, if any: the new messages go on in it.
    before = Conversation.where(account_id: account.id, inbox_id: inbox.id, contact_id: contact.id)
                         .where("additional_attributes->>'imported_from' IN (?)", imported).order(:id).first
    if before
      conversation_id = before.id
      if last > before.last_activity_at
        Conversation.where(id: before.id).update_all(last_activity_at: last, updated_at: last)
      end
      counts[:continued] += 1
    else
      # The display id comes from the trigger of the table, as with any conversation.
      conversation_id = Conversation.insert!({ account_id: account.id, inbox_id: inbox.id, contact_id: contact.id,
                                               contact_inbox_id: contact_inbox.id,
                                               status: Conversation.statuses[:resolved], created_at: first, updated_at: last,
                                               last_activity_at: last,
                                               additional_attributes: { imported_from: 'whatsapp_business_app' } },
                                             returning: %w[id]).first['id']
    end
    rows = messages.map do |m|
      at = Time.zone.at(m['at'].to_i)
      mine = m['from_me'] ? true : false
      { account_id: account.id, inbox_id: inbox.id, conversation_id: conversation_id,
        message_type: mine ? 1 : 0, content: m['text'].to_s, content_type: 0, private: false,
        status: statuses.fetch(m['status'].to_s, mine ? 1 : 0), source_id: m['id'],
        sender_type: mine ? nil : 'Contact', sender_id: mine ? nil : contact.id,
        content_attributes: mine ? { external_echo: true, imported: true } : { imported: true },
        created_at: at, updated_at: at }
    end
    written = Message.insert_all!(rows, returning: %w[id source_id]).to_h { |r| [r['source_id'], r['id']] }
    # The attachments, from the files beside the file of the chats; one that is not there is left out.
    messages.each do |m|
      next if m['file'].to_s.empty?

      file = File.expand_path(m['file'], base)
      next counts[:missing] += 1 unless file.start_with?(base) && File.file?(file)

      attachment = Attachment.new(account_id: account.id, message_id: written[m['id']],
                                  file_type: kinds.fetch(m['type'].to_s, :file))
      attachment.file.attach(io: File.open(file, 'rb'), filename: m['filename'].to_s.empty? ? File.basename(file) : m['filename'],
                             content_type: m['mime'].to_s.empty? ? 'application/octet-stream' : m['mime'])
      begin
        attachment.save!
      rescue ActiveRecord::RecordInvalid
        # A file Chatwoot does not take (its size or its kind): the message stays, without it.
        counts[:refused] += 1
      end
    end
  end
  raise ActiveRecord::Rollback if dry
end

puts "#{dry ? 'Would write' : 'Written'}: #{counts[:conversations]} conversations (#{counts[:continued]} went on), " \
     "#{counts[:messages]} messages, #{counts[:attachments]} attachments (#{counts[:missing]} files not found, #{counts[:refused]} refused), " \
     "#{counts[:contacts]} new contacts; #{counts[:already]} chats already there, #{counts[:empty]} empty"

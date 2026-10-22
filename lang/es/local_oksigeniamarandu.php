<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Spanish strings for local_oksigeniamarandu.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['error:language'] = 'Un código de idioma como en_US o es.';
$string['error:meta'] = 'Meta no ha aceptado el mensaje (código {$a->code}): {$a->message}';
$string['error:network'] = 'No se ha podido conectar con Meta: {$a}';
$string['error:notconfigured'] = 'La cuenta de WhatsApp del sitio aún no está conectada.';
$string['error:parameter'] = 'Meta no reconoce algún dato: revisa el identificador del número y la plantilla. ({$a->message})';
$string['error:phone'] = 'Escribe el número con + y el prefijo del país: +34 6xx xxx xxx en España.';
$string['error:recipientnotallowed'] = 'Este móvil no está en la lista del número de prueba. Añádelo en Meta (API Setup, «To») y confirma el código que le llega.';
$string['error:templatename'] = 'Solo minúsculas, números y _, como en Meta.';
$string['error:templatenotfound'] = 'Meta no tiene ninguna plantilla aprobada con ese nombre en ese idioma.';
$string['error:token'] = 'Meta no acepta el token de acceso: puede haber caducado o faltarle permisos. Pega un token permanente de un usuario del sistema.';
$string['error:unexpected'] = 'Meta ha respondido algo que esta versión no entiende.';
$string['pluginname'] = 'Oksigenia Marandu para WhatsApp';
$string['privacy:metadata'] = 'Esta versión solo manda un mensaje de prueba a un número que escribe el administrador; no guarda datos personales.';
$string['settings:accesstoken'] = 'Token de acceso';
$string['settings:accesstoken_desc'] = 'Un token permanente de un usuario del sistema del portfolio empresarial, con los permisos whatsapp_business_messaging y whatsapp_business_management. El temporal de API Setup dura unas horas. Se guarda cifrado.';
$string['settings:account'] = 'Cuenta de WhatsApp';
$string['settings:account_intro'] = 'El sitio manda los mensajes desde su propia cuenta oficial de WhatsApp Business. Pega aquí los datos de la app que el sitio tiene en Meta for Developers, en WhatsApp > API Setup.';
$string['settings:graphversion'] = 'Versión de la Graph API';
$string['settings:graphversion_desc'] = 'Déjala como está salvo que Meta retire esta versión.';
$string['settings:phonenumberid'] = 'Identificador del número';
$string['settings:phonenumberid_desc'] = 'El número que Meta asigna al teléfono del sitio, en API Setup («Phone number ID»). No es el número de teléfono.';
$string['settings:wabaid'] = 'Identificador de la cuenta de WhatsApp Business';
$string['settings:wabaid_desc'] = 'También en API Setup («WhatsApp Business Account ID»). Hace falta para dar de alta las plantillas.';
$string['testmessage'] = 'Mandar un mensaje de prueba';
$string['testmessage:gotosettings'] = 'Conectar la cuenta';
$string['testmessage:intro'] = 'Manda la plantilla de bienvenida de Meta a tu móvil para ver que la conexión funciona. Con el número de prueba de Meta, solo llega a los móviles que hayas añadido en Meta (cinco como mucho).';
$string['testmessage:language'] = 'Idioma de la plantilla';
$string['testmessage:language_help'] = 'El código de idioma de la plantilla en Meta, como en_US o es.';
$string['testmessage:notconfigured'] = 'Primero conecta la cuenta de WhatsApp del sitio.';
$string['testmessage:phone'] = 'Tu móvil';
$string['testmessage:phone_help'] = 'Con + y el prefijo del país: +34 6xx xxx xxx en España.';
$string['testmessage:send'] = 'Mandar';
$string['testmessage:sent'] = 'Enviado. Meta ha aceptado el mensaje (identificador {$a}); te llegará al móvil en unos segundos.';
$string['testmessage:template'] = 'Plantilla';
$string['testmessage:template_help'] = 'El nombre de una plantilla aprobada en Meta. hello_world viene con todas las cuentas nuevas.';

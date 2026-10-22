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

$string['contacts'] = 'Números en WhatsApp';
$string['contacts:name'] = 'Nombre en WhatsApp';
$string['contacts:none'] = 'Todavía no se ha apuntado ningún número. Se apuntan escribiendo {$a} al número del sitio, con el enlace o el código QR que les da un plugin.';
$string['contacts:phone'] = 'Número';
$string['contacts:since'] = 'Desde';
$string['contacts:standsfor'] = 'Representa a';
$string['contacts:status'] = 'Estado';
$string['contacts:unlinked'] = 'Aún sin vincular';
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
$string['privacy:metadata:consent'] = 'Cada alta y cada baja de un número, guardadas como prueba del consentimiento.';
$string['privacy:metadata:consent:action'] = 'Si el número se dio de alta o de baja.';
$string['privacy:metadata:consent:source'] = 'Cómo fue: una palabra escrita en WhatsApp, una página o el administrador.';
$string['privacy:metadata:consent:timecreated'] = 'Cuándo fue.';
$string['privacy:metadata:contact'] = 'Los números de WhatsApp que se han dado de alta o de baja.';
$string['privacy:metadata:contact:consenttime'] = 'Cuándo se dio de alta el número por última vez.';
$string['privacy:metadata:contact:lastinbound'] = 'Cuándo escribió el número al sitio por última vez, lo que permite contestarle durante 24 horas.';
$string['privacy:metadata:contact:name'] = 'El nombre de perfil que WhatsApp manda con los mensajes.';
$string['privacy:metadata:contact:optouttime'] = 'Cuándo se dio de baja el número por última vez.';
$string['privacy:metadata:contact:phone'] = 'El número de teléfono, con su prefijo de país.';
$string['privacy:metadata:contact:status'] = 'Si el número recibe avisos, está pendiente o se ha dado de baja.';
$string['privacy:metadata:link'] = 'A quién representa un número: un usuario del sitio o algo de otro plugin, como una familia.';
$string['privacy:metadata:link:contactid'] = 'El número vinculado.';
$string['privacy:metadata:link:timeclaimed'] = 'Cuándo se vinculó el número.';
$string['privacy:metadata:link:userid'] = 'El usuario al que representa el número.';
$string['privacy:metadata:whatsapp'] = 'Los mensajes salen por la WhatsApp Business Platform de Meta, con la cuenta del sitio.';
$string['privacy:metadata:whatsapp:message'] = 'El texto del aviso o de la respuesta.';
$string['privacy:metadata:whatsapp:phone'] = 'El número que lo recibe.';
$string['reply:optin'] = 'Hecho: desde ahora llegarán aquí los avisos de {$a->site}. Para dejar de recibirlos, basta con escribir {$a->optout}.';
$string['reply:optout'] = 'Hecho: no llegarán más avisos de {$a->site} a este número. Para volver a recibirlos, basta con escribir {$a->optin}.';
$string['reply:unknowncode'] = 'Ese código no es válido o ya se ha usado. Hay que pedir uno nuevo a {$a->site}.';
$string['settings:accesstoken'] = 'Token de acceso';
$string['settings:accesstoken_desc'] = 'Un token permanente de un usuario del sistema del portfolio empresarial, con los permisos whatsapp_business_messaging y whatsapp_business_management. El temporal de API Setup dura unas horas. Se guarda cifrado.';
$string['settings:account'] = 'Cuenta de WhatsApp';
$string['settings:account_intro'] = 'El sitio manda los mensajes desde su propia cuenta oficial de WhatsApp Business. Pega aquí los datos de la app que el sitio tiene en Meta for Developers, en WhatsApp > API Setup.';
$string['settings:appsecret'] = 'Clave secreta de la app';
$string['settings:appsecret_desc'] = 'En Meta for Developers, en la app del sitio, App settings > Basic («App secret»). Con ella el sitio comprueba que lo que llega al webhook viene de Meta. Se guarda cifrada.';
$string['settings:displayphone'] = 'Número del sitio';
$string['settings:displayphone_desc'] = 'El número de WhatsApp del sitio, con + y el prefijo del país, como lo escribiría cualquiera. Va en los enlaces que abren WhatsApp para darse de alta.';
$string['settings:graphversion'] = 'Versión de la Graph API';
$string['settings:graphversion_desc'] = 'Déjala como está salvo que Meta retire esta versión.';
$string['settings:keywords'] = 'Altas y bajas';
$string['settings:keywords_intro'] = 'Para darse de alta se escribe una palabra al número del sitio, y para darse de baja, otra. Da igual en mayúsculas o minúsculas, con tilde o sin ella.';
$string['settings:optinkeywords'] = 'Palabras para el alta';
$string['settings:optinkeywords_desc'] = 'Separadas por comas. La primera es la que va en los enlaces y en las respuestas.';
$string['settings:optoutkeywords'] = 'Palabras para la baja';
$string['settings:optoutkeywords_desc'] = 'Separadas por comas. La primera es la que va en las respuestas.';
$string['settings:phonenumberid'] = 'Identificador del número';
$string['settings:phonenumberid_desc'] = 'El número que Meta asigna al teléfono del sitio, en API Setup («Phone number ID»). No es el número de teléfono.';
$string['settings:verifytoken'] = 'Token de verificación';
$string['settings:verifytoken_desc'] = 'Pégalo en Meta, junto a la URL de devolución. El sitio creó uno al instalar el plugin; puedes cambiarlo.';
$string['settings:wabaid'] = 'Identificador de la cuenta de WhatsApp Business';
$string['settings:wabaid_desc'] = 'También en API Setup («WhatsApp Business Account ID»). Hace falta para dar de alta las plantillas.';
$string['settings:webhook'] = 'Lo que Meta manda al sitio';
$string['settings:webhook_intro'] = 'En Meta for Developers, en la app del sitio, WhatsApp > Configuration, pon esta URL de devolución («Callback URL») y suscríbete al campo «messages»: {$a}';
$string['status:active'] = 'Recibe avisos';
$string['status:optedout'] = 'Dado de baja';
$string['status:pending'] = 'Pendiente del alta';
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

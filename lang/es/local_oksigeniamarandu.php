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

$string['alta:done'] = 'Listo: ya estás dado de alta';
$string['alta:for'] = 'Para {$a}';
$string['alta:howto'] = 'Se abrirá WhatsApp con tu mensaje de alta ya escrito. Solo tienes que pulsar Enviar.';
$string['alta:invalid'] = 'Este enlace no funciona';
$string['alta:invalid_help'] = 'Puede que esté incompleto o que el sitio no haya terminado de configurar WhatsApp. Pide otra vez el enlace a quien te lo mandó.';
$string['alta:leave'] = 'Darme de baja';
$string['alta:leavenote'] = 'Para dejarlos, escribe {$a} en ese chat cuando quieras. Solo se guarda tu móvil, para mandarte estos avisos.';
$string['alta:open'] = 'Abrir WhatsApp';
$string['alta:openhere'] = 'O abrir WhatsApp en este ordenador';
$string['alta:optoutnote'] = 'Se abrirá WhatsApp con {$a} escrito. Envíalo y dejarás de recibir avisos.';
$string['alta:preview'] = 'Así lo verás:';
$string['alta:qr'] = 'Código QR para darse de alta en WhatsApp';
$string['alta:reopen'] = 'Volver a abrir WhatsApp';
$string['alta:savecontact'] = 'Guardar el número';
$string['alta:savecontact_help'] = 'Así reconocerás los avisos en cuanto lleguen.';
$string['alta:scan'] = 'Escanéalo con el móvil';
$string['alta:send'] = 'Enviar';
$string['alta:stuck'] = '¿No cambia? Mira en WhatsApp que el mensaje salió (con su marca de enviado). Si aun así no cambia, pregunta en secretaría.';
$string['alta:title'] = 'Avisos de {$a} por WhatsApp';
$string['alta:wait'] = 'Esperando tu mensaje';
$string['alta:wait_help'] = 'Si ya has pulsado Enviar en WhatsApp, esto cambia solo en unos segundos.';
$string['alta:yourphone'] = 'Tu móvil';
$string['contacts'] = 'Números en WhatsApp';
$string['contacts:name'] = 'Nombre en WhatsApp';
$string['contacts:none'] = 'Todavía no se ha apuntado ningún número. Se apuntan escribiendo {$a} al número del sitio, con el enlace o el código QR que les da un plugin.';
$string['contacts:phone'] = 'Número';
$string['contacts:since'] = 'Desde';
$string['contacts:standsfor'] = 'Representa a';
$string['contacts:status'] = 'Estado';
$string['contacts:unlinked'] = 'Aún sin vincular';
$string['error:meta'] = 'Meta no ha aceptado el mensaje (código {$a->code}): {$a->message}';
$string['error:network'] = 'No se ha podido conectar con Meta: {$a}';
$string['error:notconfigured'] = 'La cuenta de WhatsApp del sitio aún no está conectada.';
$string['error:parameter'] = 'Meta no reconoce algún dato: revisa el identificador del número y la plantilla. ({$a->message})';
$string['error:phone'] = 'Escribe el número con + y el prefijo del país: +34 6xx xxx xxx en España.';
$string['error:recipientnotallowed'] = 'Este móvil no está en la lista del número de prueba. Añádelo en Meta (API Setup, «To») y confirma el código que le llega.';
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
$string['reply:unknowncode'] = 'Ese código no es válido o ya se ha usado. Pide uno nuevo a {$a->site}.';
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
$string['wizard'] = 'Configurar WhatsApp';
$string['wizard:accesstoken'] = 'Token de acceso';
$string['wizard:accesstoken_help'] = 'Empieza por EAA. Pega el permanente: el de esa página caduca en un día.';
$string['wizard:accesstoken_saved'] = 'Guardado; pega otro solo para cambiarlo';
$string['wizard:account:lead'] = 'Dos partes: copia aquí tres datos de tu app en Meta (el dibujo dice dónde están) y, luego, dos de aquí en Meta.';
$string['wizard:account:title'] = 'Conecta el WhatsApp del sitio';
$string['wizard:appsecret'] = 'Clave secreta de la app';
$string['wizard:appsecret_help'] = 'En Meta, tu app › Configuración de la app › Básica («App secret»). Con ella el sitio sabe que lo que llega viene de Meta.';
$string['wizard:appsecret_saved'] = 'Guardada; pega otra solo para cambiarla';
$string['wizard:callbackurl'] = 'URL de devolución de llamada';
$string['wizard:callbackurl_help'] = 'La dirección a la que Meta le cuenta las cosas a Marandu.';
$string['wizard:change'] = 'Cambiar los datos';
$string['wizard:check'] = 'Comprobar la conexión';
$string['wizard:checkhook'] = 'Ya lo he pegado en Meta: comprobar';
$string['wizard:connected'] = '<b>Primera parte hecha: conectado con Meta.</b> Número: {$a->number} · Nombre: {$a->name}.';
$string['wizard:copied'] = 'Copiado';
$string['wizard:copy'] = 'Copiar';
$string['wizard:done:contacts'] = 'Ver quién se ha dado de alta';
$string['wizard:done:lead'] = 'Solo falta lo más importante: que las familias se den de alta. Cada una lo hace desde su enlace privado, en el móvil, o con un código QR.';
$string['wizard:done:settings'] = 'Otros ajustes';
$string['wizard:done:testok'] = 'Llegó a {$a}';
$string['wizard:done:testpending'] = 'Mandado; Meta aún no ha confirmado que llegara';
$string['wizard:done:title'] = 'Listo: el WhatsApp del sitio está conectado';
$string['wizard:error:digits'] = 'Solo cifras: un número largo, sin espacios.';
$string['wizard:error:nosecret'] = 'Pega la clave secreta de la app: sin ella el sitio no puede saber qué viene de Meta.';
$string['wizard:error:notoken'] = 'Pega el token de acceso.';
$string['wizard:hookbad'] = 'Meta aún no ha llamado a la dirección. Mira que pulsaste «Verificar y guardar» y que «messages» está suscrito.';
$string['wizard:hookok'] = 'Meta ya habla con Marandu.';
$string['wizard:meta:apisetup'] = 'Configuración de la API';
$string['wizard:meta:phonenumberid'] = 'Identificador del número de teléfono';
$string['wizard:meta:temptoken'] = 'Token de acceso temporal';
$string['wizard:meta:wabaid'] = 'Identificador de la cuenta de WhatsApp Business';
$string['wizard:next:done'] = 'Seguir';
$string['wizard:next:test'] = 'Seguir: mensaje de prueba';
$string['wizard:noapp'] = '¿Aún no hay app en Meta? Hace falta una, de tipo WhatsApp, en Meta for Developers.';
$string['wizard:phonenumberid'] = 'Identificador del número de teléfono';
$string['wizard:phonenumberid_help'] = 'Un número largo; no es el teléfono.';
$string['wizard:progress'] = 'Progreso';
$string['wizard:step:account'] = 'Cuenta';
$string['wizard:step:test'] = 'Prueba';
$string['wizard:stepof'] = 'Paso {$a->num} de {$a->total} · {$a->name}';
$string['wizard:test:again'] = 'Mandarlo otra vez';
$string['wizard:test:english'] = 'Llega en inglés: es el mensaje de prueba que Meta pone en todas las cuentas.';
$string['wizard:test:lead'] = 'Así ves que los avisos llegan de verdad. Escribe tu móvil, mándalo y mira el teléfono.';
$string['wizard:test:nope'] = 'No me ha llegado';
$string['wizard:test:nope_help'] = 'Mira el número, con el prefijo del país. Si usas el número de prueba de Meta, añade tu móvil allí (WhatsApp › Configuración de la API › «Para» › Administrar la lista) y escribe el código que te llega. Si se queda en «Mandado», Meta no ha podido entregarlo: vuelve a probar en un minuto.';
$string['wizard:test:phone'] = 'Tu móvil';
$string['wizard:test:phone_help'] = 'Con + y el prefijo del país. Con el número de prueba de Meta, solo llega a los móviles que hayas añadido allí (cinco como mucho).';
$string['wizard:test:preview'] = 'Lo que verás en el móvil';
$string['wizard:test:send'] = 'Mandar la prueba';
$string['wizard:test:title'] = 'Mándate un mensaje de prueba';
$string['wizard:track:delivered'] = 'Ha llegado a tu móvil';
$string['wizard:track:failed'] = 'Meta no ha podido entregarlo:';
$string['wizard:track:replied'] = 'Si quieres, contesta algo desde el móvil: así ves que también llega lo que escriben las familias';
$string['wizard:track:sent'] = 'Mandado';
$string['wizard:verifytoken'] = 'Token de verificación';
$string['wizard:verifytoken_help'] = 'Una clave que ha inventado Marandu; no hay que apuntarla.';
$string['wizard:wabaid'] = 'Identificador de la cuenta de WhatsApp Business';
$string['wizard:wabaid_help'] = 'Justo debajo del anterior.';
$string['wizard:webhook:lead'] = 'En Meta, tu app › WhatsApp › Configuración › Webhook › «Editar»: pega las dos primeras y suscríbete a «messages». La tercera va al revés, de Meta aquí.';
$string['wizard:webhook:title'] = 'Segunda parte: que Meta le cuente a Marandu lo que pasa';
$string['wizard:where'] = 'Página de Meta for Developers, WhatsApp, Configuración de la API, con los datos marcados';
$string['wizard:where:caption'] = 'En Meta for Developers, tu app › WhatsApp › Configuración de la API.';
$string['wizard:where:fold'] = 'Dónde están en Meta';
$string['wizard:where:temporary'] = 'Este no: caduca en 24 horas';
$string['wizard:where:token'] = 'El token permanente, en otro sitio: Configuración del negocio › Usuarios del sistema › Generar token.';

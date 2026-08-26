<?php

declare(strict_types=1);

namespace App\Services;

/**
 * PromptPress — Microcopy del frontend público, por idioma.
 *
 * Los textos que NO escribe el usuario ni la IA (botón de enviar de un
 * formulario, títulos de columna del footer, banner de cookies…) estaban
 * fijos en castellano dentro del renderizador. En un sitio en francés se
 * colaban entre el contenido traducido.
 *
 * Regla de resolución en los puntos de uso:
 *   1. Si el usuario ha escrito su propio texto → manda el suyo, siempre.
 *   2. Si no, o si lo que hay guardado es literalmente el default castellano
 *      histórico (instalaciones anteriores a esto) → texto de este diccionario
 *      en el idioma del sitio.
 *
 * Cambiar el idioma en Ajustes cambia esto en el siguiente render, sin tocar
 * la base de datos: es la parte SEGURA del cambio de idioma. El contenido
 * escrito (páginas, títulos, menú personalizado) NO se traduce solo.
 *
 * Para añadir un idioma: añadirlo en LanguageService::LANGUAGES y completar
 * la columna aquí. `tests/site_language.php` falla si queda algún hueco.
 */
final class Microcopy
{
    /**
     * Idiomas en los que se garantizan las claves de MÓDULO (`shop.`,
     * `booking.`). Los idiomas fuera de esta lista caen a castellano.
     *
     * Falta `eu` a propósito: en un checkout, donde el texto tiene efectos
     * contractuales, prefiero castellano correcto a euskera que no puedo dar
     * por revisado. Añadirlo es rellenar la columna — la maquinaria ya está.
     * Las claves del núcleo (footer, formularios, cookies) sí están completas
     * en los 7 idiomas.
     *
     * @var array<int,string>
     */
    public const MODULE_LANGUAGES = ['es', 'en', 'ca', 'gl', 'fr', 'pt'];

    /** Prefijos de clave que se consideran "de módulo". */
    private const MODULE_PREFIXES = ['shop.', 'booking.', 'resources.', 'mail.'];

    /**
     * key => [código de idioma => texto]. El castellano es la fuente y el
     * fallback de última instancia.
     *
     * @var array<string, array<string, string>>
     */
    private const STRINGS = [
        // --- Navegación y footer -------------------------------------------
        'nav.primary_aria' => [
            'es' => 'Navegación principal', 'en' => 'Main navigation', 'ca' => 'Navegació principal',
            'gl' => 'Navegación principal', 'eu' => 'Nabigazio nagusia', 'fr' => 'Navigation principale',
            'pt' => 'Navegação principal',
        ],
        'nav.footer_aria' => [
            'es' => 'Navegación', 'en' => 'Navigation', 'ca' => 'Navegació',
            'gl' => 'Navegación', 'eu' => 'Nabigazioa', 'fr' => 'Navigation',
            'pt' => 'Navegação',
        ],
        'nav.language_aria' => [
            'es' => 'Idioma', 'en' => 'Language', 'ca' => 'Idioma',
            'gl' => 'Idioma', 'eu' => 'Hizkuntza', 'fr' => 'Langue',
            'pt' => 'Idioma',
        ],
        'nav.legal_aria' => [
            'es' => 'Enlaces legales', 'en' => 'Legal links', 'ca' => 'Enllaços legals',
            'gl' => 'Ligazóns legais', 'eu' => 'Lege-estekak', 'fr' => 'Liens légaux',
            'pt' => 'Ligações legais',
        ],
        'footer.explore' => [
            'es' => 'Explora', 'en' => 'Explore', 'ca' => 'Explora',
            'gl' => 'Explora', 'eu' => 'Arakatu', 'fr' => 'Explorer',
            'pt' => 'Explorar',
        ],
        'footer.legal' => [
            'es' => 'Legal', 'en' => 'Legal', 'ca' => 'Legal',
            'gl' => 'Legal', 'eu' => 'Legala', 'fr' => 'Mentions légales',
            'pt' => 'Legal',
        ],
        'footer.contact' => [
            'es' => 'Contacto', 'en' => 'Contact', 'ca' => 'Contacte',
            'gl' => 'Contacto', 'eu' => 'Harremana', 'fr' => 'Contact',
            'pt' => 'Contacto',
        ],
        'footer.social' => [
            'es' => 'Síguenos', 'en' => 'Follow us', 'ca' => 'Segueix-nos',
            'gl' => 'Séguenos', 'eu' => 'Jarraitu gaitzazu', 'fr' => 'Suivez-nous',
            'pt' => 'Siga-nos',
        ],
        'footer.social_aria' => [
            'es' => 'Redes sociales', 'en' => 'Social media', 'ca' => 'Xarxes socials',
            'gl' => 'Redes sociais', 'eu' => 'Sare sozialak', 'fr' => 'Réseaux sociaux',
            'pt' => 'Redes sociais',
        ],
        'footer.newsletter' => [
            'es' => 'Newsletter', 'en' => 'Newsletter', 'ca' => 'Newsletter',
            'gl' => 'Newsletter', 'eu' => 'Newsletter', 'fr' => 'Newsletter',
            'pt' => 'Newsletter',
        ],
        'footer.newsletter_heading' => [
            'es' => 'Suscríbete a nuestra newsletter', 'en' => 'Subscribe to our newsletter',
            'ca' => 'Subscriu-te a la nostra newsletter', 'gl' => 'Subscríbete á nosa newsletter',
            'eu' => 'Harpidetu gure newsletterrera', 'fr' => 'Abonnez-vous à notre newsletter',
            'pt' => 'Subscreva a nossa newsletter',
        ],
        'footer.newsletter_cta' => [
            'es' => 'Suscribirme', 'en' => 'Subscribe', 'ca' => 'Subscriure\'m',
            'gl' => 'Subscribirme', 'eu' => 'Harpidetu', 'fr' => 'S\'abonner',
            'pt' => 'Subscrever',
        ],

        // --- Formularios ----------------------------------------------------
        'form.submit' => [
            'es' => 'Enviar', 'en' => 'Send', 'ca' => 'Enviar',
            'gl' => 'Enviar', 'eu' => 'Bidali', 'fr' => 'Envoyer',
            'pt' => 'Enviar',
        ],
        'form.success' => [
            'es' => 'Gracias, hemos recibido tu mensaje.', 'en' => 'Thanks, we have received your message.',
            'ca' => 'Gràcies, hem rebut el teu missatge.', 'gl' => 'Grazas, recibimos a túa mensaxe.',
            'eu' => 'Eskerrik asko, zure mezua jaso dugu.', 'fr' => 'Merci, nous avons bien reçu votre message.',
            'pt' => 'Obrigado, recebemos a sua mensagem.',
        ],

        'form.session_expired' => [
            'es' => 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.',
            'en' => 'Your session has expired. Please reload the page and try again.',
            'ca' => 'La sessió ha caducat. Torna a carregar la pàgina i prova-ho de nou.',
            'gl' => 'A sesión caducou. Recarga a páxina e téntao de novo.',
            'eu' => 'Saioa iraungi da. Kargatu orria berriro eta saiatu berriz.',
            'fr' => 'Votre session a expiré. Rechargez la page et réessayez.',
            'pt' => 'A sessão expirou. Recarregue a página e tente novamente.',
        ],
        'form.error' => [
            'es' => 'No se pudo enviar el formulario. Revisa los campos e inténtalo de nuevo.',
            'en' => 'The form could not be sent. Please check the fields and try again.',
            'ca' => 'No s\'ha pogut enviar el formulari. Revisa els camps i torna-ho a provar.',
            'gl' => 'Non se puido enviar o formulario. Revisa os campos e téntao de novo.',
            'eu' => 'Ezin izan da inprimakia bidali. Egiaztatu eremuak eta saiatu berriro.',
            'fr' => 'Le formulaire n\'a pas pu être envoyé. Vérifiez les champs et réessayez.',
            'pt' => 'Não foi possível enviar o formulário. Verifique os campos e tente novamente.',
        ],
        'form.rate_limited' => [
            'es' => 'Hemos recibido varios envíos seguidos. Espera unos minutos antes de volver a intentarlo.',
            'en' => 'We have received several submissions in a row. Please wait a few minutes before trying again.',
            'ca' => 'Hem rebut diversos enviaments seguits. Espera uns minuts abans de tornar-ho a provar.',
            'gl' => 'Recibimos varios envíos seguidos. Agarda uns minutos antes de tentalo de novo.',
            'eu' => 'Bidalketa bat baino gehiago jaso ditugu jarraian. Itxaron minutu batzuk berriro saiatu aurretik.',
            'fr' => 'Nous avons reçu plusieurs envois à la suite. Patientez quelques minutes avant de réessayer.',
            'pt' => 'Recebemos vários envios seguidos. Aguarde alguns minutos antes de tentar de novo.',
        ],

        // FORMS-LANG T2 — textos que estaban incrustados en el renderizador.
        'form.select_placeholder' => [
            'es' => 'Selecciona una opción', 'en' => 'Select an option',
            'ca' => 'Selecciona una opció', 'gl' => 'Escolle unha opción',
            'eu' => 'Hautatu aukera bat', 'fr' => 'Sélectionnez une option',
            'pt' => 'Selecione uma opção',
        ],
        'form.file_button' => [
            'es' => 'Seleccionar archivo', 'en' => 'Choose file',
            'ca' => 'Selecciona un fitxer', 'gl' => 'Escoller ficheiro',
            'eu' => 'Hautatu fitxategia', 'fr' => 'Choisir un fichier',
            'pt' => 'Selecionar ficheiro',
        ],
        'form.file_empty' => [
            'es' => 'Ningún archivo seleccionado', 'en' => 'No file selected',
            'ca' => 'Cap fitxer seleccionat', 'gl' => 'Ningún ficheiro seleccionado',
            'eu' => 'Ez da fitxategirik hautatu', 'fr' => 'Aucun fichier sélectionné',
            'pt' => 'Nenhum ficheiro selecionado',
        ],
        'form.file_help' => [
            'es' => 'Formatos permitidos: {formats}. Máximo {max} MB.',
            'en' => 'Allowed formats: {formats}. Maximum {max} MB.',
            'ca' => 'Formats permesos: {formats}. Màxim {max} MB.',
            'gl' => 'Formatos permitidos: {formats}. Máximo {max} MB.',
            'eu' => 'Onartutako formatuak: {formats}. Gehienez {max} MB.',
            'fr' => 'Formats acceptés : {formats}. Maximum {max} Mo.',
            'pt' => 'Formatos permitidos: {formats}. Máximo {max} MB.',
        ],
        'form.marketing_consent' => [
            'es' => 'Acepto recibir comunicaciones comerciales y novedades por email. Puedo darme de baja en cualquier momento.',
            'en' => 'I agree to receive commercial communications and news by email. I can unsubscribe at any time.',
            'ca' => 'Accepto rebre comunicacions comercials i novetats per correu electrònic. Puc donar-me de baixa en qualsevol moment.',
            'gl' => 'Acepto recibir comunicacións comerciais e novidades por correo electrónico. Podo darme de baixa en calquera momento.',
            'eu' => 'Onartzen dut merkataritza-komunikazioak eta berriak posta elektronikoz jasotzea. Noiznahi baja eman dezaket.',
            'fr' => 'J\'accepte de recevoir des communications commerciales et des actualités par e-mail. Je peux me désabonner à tout moment.',
            'pt' => 'Aceito receber comunicações comerciais e novidades por email. Posso cancelar a subscrição a qualquer momento.',
        ],

        // Nota de privacidad bajo el formulario (E-GDPR G5). Tiene efectos
        // legales: traducciones revisadas, nunca generadas al vuelo.
        'form.privacy_basis_consent' => [
            'es' => 'tras tu consentimiento explícito',
            'en' => 'on the basis of your explicit consent',
            'ca' => 'després del teu consentiment explícit',
            'gl' => 'tras o teu consentimento explícito',
            'eu' => 'zure baimen esplizituaren ondoren',
            'fr' => 'sur la base de votre consentement explicite',
            'pt' => 'com base no seu consentimento explícito',
        ],
        'form.privacy_basis_contract' => [
            'es' => 'para gestionar tu solicitud o servicio contratado',
            'en' => 'to handle your request or the service you have contracted',
            'ca' => 'per gestionar la teva sol·licitud o servei contractat',
            'gl' => 'para xestionar a túa solicitude ou servizo contratado',
            'eu' => 'zure eskaera edo kontratatutako zerbitzua kudeatzeko',
            'fr' => 'afin de traiter votre demande ou le service souscrit',
            'pt' => 'para gerir o seu pedido ou serviço contratado',
        ],
        'form.privacy_basis_legitimate' => [
            'es' => 'en base a nuestro interés legítimo de atender consultas',
            'en' => 'on the basis of our legitimate interest in answering enquiries',
            'ca' => 'sobre la base del nostre interès legítim d\'atendre consultes',
            'gl' => 'con base no noso interese lexítimo de atender consultas',
            'eu' => 'kontsultei erantzuteko dugun interes legitimoan oinarrituta',
            'fr' => 'sur la base de notre intérêt légitime à répondre aux demandes',
            'pt' => 'com base no nosso interesse legítimo em responder a consultas',
        ],
        'form.privacy_text' => [
            'es' => 'Tus datos se tratarán {basis}.', 'en' => 'Your data will be processed {basis}.',
            'ca' => 'Les teves dades es tractaran {basis}.', 'gl' => 'Os teus datos trataranse {basis}.',
            'eu' => 'Zure datuak {basis} tratatuko dira.', 'fr' => 'Vos données seront traitées {basis}.',
            'pt' => 'Os seus dados serão tratados {basis}.',
        ],
        'form.privacy_text_retention' => [
            'es' => 'Tus datos se tratarán {basis} y se conservarán durante {retention}.',
            'en' => 'Your data will be processed {basis} and kept for {retention}.',
            'ca' => 'Les teves dades es tractaran {basis} i es conservaran durant {retention}.',
            'gl' => 'Os teus datos trataranse {basis} e conservaranse durante {retention}.',
            'eu' => 'Zure datuak {basis} tratatuko dira eta {retention} gordeko dira.',
            'fr' => 'Vos données seront traitées {basis} et conservées pendant {retention}.',
            'pt' => 'Os seus dados serão tratados {basis} e conservados durante {retention}.',
        ],
        'form.privacy_link' => [
            'es' => 'Más información en nuestra política de privacidad',
            'en' => 'More information in our privacy policy',
            'ca' => 'Més informació a la nostra política de privacitat',
            'gl' => 'Máis información na nosa política de privacidade',
            'eu' => 'Informazio gehiago gure pribatutasun-politikan',
            'fr' => 'Plus d\'informations dans notre politique de confidentialité',
            'pt' => 'Mais informação na nossa política de privacidade',
        ],
        'form.retention_default' => [
            'es' => '12 meses tras la última comunicación',
            'en' => '12 months after the last communication',
            'ca' => '12 mesos després de l\'última comunicació',
            'gl' => '12 meses tras a última comunicación',
            'eu' => 'azken komunikaziotik 12 hilabetera arte',
            'fr' => '12 mois après le dernier échange',
            'pt' => '12 meses após a última comunicação',
        ],

        'form.not_found' => [
            'es' => 'Formulario no encontrado.', 'en' => 'Form not found.',
            'ca' => 'Formulari no trobat.', 'gl' => 'Formulario non atopado.',
            'eu' => 'Ez da inprimakia aurkitu.', 'fr' => 'Formulaire introuvable.',
            'pt' => 'Formulário não encontrado.',
        ],
        'form.expired_page' => [
            'es' => 'La página llevaba demasiado tiempo abierta. Recárgala e inténtalo de nuevo.',
            'en' => 'The page had been open for too long. Please reload it and try again.',
            'ca' => 'La pàgina feia massa temps que era oberta. Torna-la a carregar i prova-ho de nou.',
            'gl' => 'A páxina levaba demasiado tempo aberta. Recárgaa e téntao de novo.',
            'eu' => 'Orria denbora luzeegian egon da irekita. Kargatu berriro eta saiatu berriz.',
            'fr' => 'La page est restée ouverte trop longtemps. Rechargez-la et réessayez.',
            'pt' => 'A página esteve aberta demasiado tempo. Recarregue-a e tente novamente.',
        ],
        'form.check_fields' => [
            'es' => 'Revisa estos campos: {fields}.', 'en' => 'Please check these fields: {fields}.',
            'ca' => 'Revisa aquests camps: {fields}.', 'gl' => 'Revisa estes campos: {fields}.',
            'eu' => 'Berrikusi eremu hauek: {fields}.', 'fr' => 'Vérifiez ces champs : {fields}.',
            'pt' => 'Verifique estes campos: {fields}.',
        ],
        'form.retention_job' => [
            'es' => '12 meses tras el cierre del proceso de selección',
            'en' => '12 months after the selection process closes',
            'ca' => '12 mesos després del tancament del procés de selecció',
            'gl' => '12 meses tras o peche do proceso de selección',
            'eu' => 'hautaketa-prozesua itxi eta 12 hilabetera arte',
            'fr' => '12 mois après la clôture du processus de recrutement',
            'pt' => '12 meses após o encerramento do processo de seleção',
        ],

        // FORMS-LANG T3/T4 — textos de las PLANTILLAS de formulario. A
        // diferencia del resto del diccionario, esto no se resuelve al pintar:
        // se copia a la BD al crear el formulario, en el idioma principal del
        // sitio, y a partir de ahí lo manda el usuario. Los `name` de los
        // campos NO están aquí a propósito: son la clave de los datos
        // guardados y de las variables del autorespondedor, y no se traducen.
        'form.tpl.field.name' => [
            'es' => 'Nombre', 'en' => 'Name', 'ca' => 'Nom', 'gl' => 'Nome',
            'eu' => 'Izena', 'fr' => 'Nom', 'pt' => 'Nome',
        ],
        'form.tpl.field.email' => [
            'es' => 'Email', 'en' => 'Email', 'ca' => 'Correu electrònic', 'gl' => 'Correo electrónico',
            'eu' => 'Helbide elektronikoa', 'fr' => 'E-mail', 'pt' => 'Email',
        ],
        'form.tpl.field.message' => [
            'es' => 'Mensaje', 'en' => 'Message', 'ca' => 'Missatge', 'gl' => 'Mensaxe',
            'eu' => 'Mezua', 'fr' => 'Message', 'pt' => 'Mensagem',
        ],
        'form.tpl.field.phone' => [
            'es' => 'Teléfono', 'en' => 'Phone', 'ca' => 'Telèfon', 'gl' => 'Teléfono',
            'eu' => 'Telefonoa', 'fr' => 'Téléphone', 'pt' => 'Telefone',
        ],
        'form.tpl.field.need' => [
            'es' => '¿Qué necesitas?', 'en' => 'What do you need?', 'ca' => 'Què necessites?',
            'gl' => 'Que necesitas?', 'eu' => 'Zer behar duzu?', 'fr' => 'De quoi avez-vous besoin ?',
            'pt' => 'Do que precisa?',
        ],
        'form.tpl.field.date' => [
            'es' => 'Fecha preferida', 'en' => 'Preferred date', 'ca' => 'Data preferida',
            'gl' => 'Data preferida', 'eu' => 'Nahiago duzun data', 'fr' => 'Date souhaitée',
            'pt' => 'Data preferida',
        ],
        'form.tpl.field.cv' => [
            'es' => 'CV', 'en' => 'CV', 'ca' => 'CV', 'gl' => 'CV',
            'eu' => 'CVa', 'fr' => 'CV', 'pt' => 'CV',
        ],

        'form.tpl.contact.heading' => [
            'es' => 'Contacta con nosotros', 'en' => 'Get in touch', 'ca' => 'Contacta amb nosaltres',
            'gl' => 'Contacta connosco', 'eu' => 'Jar zaitez gurekin harremanetan',
            'fr' => 'Contactez-nous', 'pt' => 'Fale connosco',
        ],
        'form.tpl.contact.success' => [
            'es' => 'Gracias, te contactaremos pronto.', 'en' => 'Thanks, we will get back to you soon.',
            'ca' => 'Gràcies, et contactarem aviat.', 'gl' => 'Grazas, contactarémoste pronto.',
            'eu' => 'Eskerrik asko, laster jarriko gara zurekin harremanetan.',
            'fr' => 'Merci, nous vous recontacterons rapidement.',
            'pt' => 'Obrigado, entraremos em contacto em breve.',
        ],
        'form.tpl.newsletter.heading' => [
            'es' => 'Suscríbete a nuestra newsletter', 'en' => 'Subscribe to our newsletter',
            'ca' => 'Subscriu-te a la nostra newsletter', 'gl' => 'Subscríbete á nosa newsletter',
            'eu' => 'Harpidetu gure newsletterrera', 'fr' => 'Abonnez-vous à notre newsletter',
            'pt' => 'Subscreva a nossa newsletter',
        ],
        'form.tpl.newsletter.success' => [
            'es' => '¡Listo! Revisa tu correo para confirmar la suscripción.',
            'en' => 'All set! Check your inbox to confirm the subscription.',
            'ca' => 'Fet! Revisa el teu correu per confirmar la subscripció.',
            'gl' => 'Listo! Revisa o teu correo para confirmar a subscrición.',
            'eu' => 'Eginda! Begiratu zure posta harpidetza berresteko.',
            'fr' => 'C\'est fait ! Consultez votre boîte mail pour confirmer l\'abonnement.',
            'pt' => 'Pronto! Verifique o seu email para confirmar a subscrição.',
        ],
        'form.tpl.newsletter.submit' => [
            'es' => 'Suscribirme', 'en' => 'Subscribe', 'ca' => 'Subscriure\'m',
            'gl' => 'Subscribirme', 'eu' => 'Harpidetu', 'fr' => 'S\'abonner',
            'pt' => 'Subscrever',
        ],
        'form.tpl.quote.heading' => [
            'es' => 'Pide tu presupuesto', 'en' => 'Request your quote', 'ca' => 'Demana el teu pressupost',
            'gl' => 'Pide o teu orzamento', 'eu' => 'Eskatu zure aurrekontua',
            'fr' => 'Demandez votre devis', 'pt' => 'Peça o seu orçamento',
        ],
        'form.tpl.quote.success' => [
            'es' => 'Gracias, prepararemos tu presupuesto y te lo enviaremos pronto.',
            'en' => 'Thanks, we will prepare your quote and send it to you shortly.',
            'ca' => 'Gràcies, prepararem el teu pressupost i te l\'enviarem aviat.',
            'gl' => 'Grazas, prepararemos o teu orzamento e enviarémolo pronto.',
            'eu' => 'Eskerrik asko, zure aurrekontua prestatu eta laster bidaliko dizugu.',
            'fr' => 'Merci, nous préparons votre devis et vous l\'envoyons rapidement.',
            'pt' => 'Obrigado, vamos preparar o seu orçamento e enviá-lo em breve.',
        ],
        'form.tpl.quote.submit' => [
            'es' => 'Solicitar presupuesto', 'en' => 'Request quote', 'ca' => 'Sol·licitar pressupost',
            'gl' => 'Solicitar orzamento', 'eu' => 'Aurrekontua eskatu', 'fr' => 'Demander un devis',
            'pt' => 'Pedir orçamento',
        ],
        'form.tpl.booking.heading' => [
            'es' => 'Reserva tu cita', 'en' => 'Book your appointment', 'ca' => 'Reserva la teva cita',
            'gl' => 'Reserva a túa cita', 'eu' => 'Erreserbatu zure hitzordua',
            'fr' => 'Réservez votre rendez-vous', 'pt' => 'Marque a sua consulta',
        ],
        'form.tpl.booking.success' => [
            'es' => 'Hemos recibido tu solicitud de reserva. Te confirmaremos en breve.',
            'en' => 'We have received your booking request. We will confirm shortly.',
            'ca' => 'Hem rebut la teva sol·licitud de reserva. T\'ho confirmarem aviat.',
            'gl' => 'Recibimos a túa solicitude de reserva. Confirmarémoscho en breve.',
            'eu' => 'Zure erreserba-eskaera jaso dugu. Laster berretsiko dizugu.',
            'fr' => 'Nous avons bien reçu votre demande de réservation. Nous vous confirmerons sous peu.',
            'pt' => 'Recebemos o seu pedido de marcação. Confirmaremos em breve.',
        ],
        'form.tpl.booking.submit' => [
            'es' => 'Reservar', 'en' => 'Book', 'ca' => 'Reservar', 'gl' => 'Reservar',
            'eu' => 'Erreserbatu', 'fr' => 'Réserver', 'pt' => 'Reservar',
        ],
        'form.tpl.job.heading' => [
            'es' => 'Trabaja con nosotros', 'en' => 'Work with us', 'ca' => 'Treballa amb nosaltres',
            'gl' => 'Traballa connosco', 'eu' => 'Egin lan gurekin', 'fr' => 'Travaillez avec nous',
            'pt' => 'Trabalhe connosco',
        ],
        'form.tpl.job.success' => [
            'es' => 'Gracias por tu interés. Revisaremos tu candidatura.',
            'en' => 'Thanks for your interest. We will review your application.',
            'ca' => 'Gràcies pel teu interès. Revisarem la teva candidatura.',
            'gl' => 'Grazas polo teu interese. Revisaremos a túa candidatura.',
            'eu' => 'Eskerrik asko zure interesagatik. Zure hautagaitza aztertuko dugu.',
            'fr' => 'Merci de votre intérêt. Nous étudierons votre candidature.',
            'pt' => 'Obrigado pelo seu interesse. Vamos analisar a sua candidatura.',
        ],
        'form.tpl.job.submit' => [
            'es' => 'Enviar candidatura', 'en' => 'Send application', 'ca' => 'Enviar candidatura',
            'gl' => 'Enviar candidatura', 'eu' => 'Bidali hautagaitza', 'fr' => 'Envoyer ma candidature',
            'pt' => 'Enviar candidatura',
        ],
        'form.tpl.default_heading' => [
            'es' => 'Formulario', 'en' => 'Form', 'ca' => 'Formulari', 'gl' => 'Formulario',
            'eu' => 'Inprimakia', 'fr' => 'Formulaire', 'pt' => 'Formulário',
        ],
        'form.tpl.autoresponder_subject' => [
            'es' => 'Hemos recibido tu mensaje', 'en' => 'We have received your message',
            'ca' => 'Hem rebut el teu missatge', 'gl' => 'Recibimos a túa mensaxe',
            'eu' => 'Zure mezua jaso dugu', 'fr' => 'Nous avons bien reçu votre message',
            'pt' => 'Recebemos a sua mensagem',
        ],
        // OJO: se lee con `template()`, no con `t()`. Las variables van con
        // llaves DOBLES (`{{nombre}}`) y `t()` las dejaría a medias.
        'form.tpl.autoresponder_body' => [
            'es' => "Hola {{nombre}}:\n\nGracias por escribirnos. Hemos recibido tu mensaje y te responderemos lo antes posible.\n\nUn saludo,\n{{sitio}}",
            'en' => "Hi {{nombre}},\n\nThanks for writing to us. We have received your message and will reply as soon as possible.\n\nBest regards,\n{{sitio}}",
            'ca' => "Hola {{nombre}}:\n\nGràcies per escriure'ns. Hem rebut el teu missatge i et respondrem al més aviat possible.\n\nSalutacions,\n{{sitio}}",
            'gl' => "Ola {{nombre}}:\n\nGrazas por escribirnos. Recibimos a túa mensaxe e responderémosche o antes posible.\n\nUn saúdo,\n{{sitio}}",
            'eu' => "Kaixo {{nombre}}:\n\nEskerrik asko idazteagatik. Zure mezua jaso dugu eta ahalik eta lasterren erantzungo dizugu.\n\nAgur bero bat,\n{{sitio}}",
            'fr' => "Bonjour {{nombre}},\n\nMerci de nous avoir écrit. Nous avons bien reçu votre message et vous répondrons dans les meilleurs délais.\n\nCordialement,\n{{sitio}}",
            'pt' => "Olá {{nombre}}:\n\nObrigado por nos escrever. Recebemos a sua mensagem e responderemos o mais depressa possível.\n\nCom os melhores cumprimentos,\n{{sitio}}",
        ],

        // --- Banner de cookies ----------------------------------------------
        'cookies.title' => [
            'es' => 'Cookies en este sitio', 'en' => 'Cookies on this site',
            'ca' => 'Cookies en aquest lloc', 'gl' => 'Cookies neste sitio',
            'eu' => 'Cookieak webgune honetan', 'fr' => 'Cookies sur ce site',
            'pt' => 'Cookies neste site',
        ],
        'cookies.description' => [
            'es' => 'Usamos cookies necesarias para que la web funcione. Si lo aceptas, también usaremos otras para analítica y mejorar tu experiencia. Puedes cambiar tu decisión cuando quieras.',
            'en' => 'We use necessary cookies to make the site work. If you accept, we will also use others for analytics and to improve your experience. You can change your choice at any time.',
            'ca' => 'Fem servir cookies necessàries perquè el web funcioni. Si ho acceptes, també en farem servir d\'altres per a analítica i per millorar la teva experiència. Pots canviar la teva decisió quan vulguis.',
            'gl' => 'Usamos cookies necesarias para que a web funcione. Se o aceptas, tamén usaremos outras para analítica e mellorar a túa experiencia. Podes cambiar a túa decisión cando queiras.',
            'eu' => 'Webgunea funtzionatzeko beharrezkoak diren cookieak erabiltzen ditugu. Onartzen baduzu, beste batzuk ere erabiliko ditugu analitikarako eta zure esperientzia hobetzeko. Zure erabakia noiznahi alda dezakezu.',
            'fr' => 'Nous utilisons des cookies nécessaires au fonctionnement du site. Si vous acceptez, nous en utiliserons aussi d\'autres à des fins de mesure d\'audience et pour améliorer votre expérience. Vous pouvez changer d\'avis à tout moment.',
            'pt' => 'Usamos cookies necessários para que o site funcione. Se aceitar, usaremos também outros para análise e para melhorar a sua experiência. Pode mudar a sua decisão quando quiser.',
        ],
        'cookies.accept' => [
            'es' => 'Aceptar todas', 'en' => 'Accept all', 'ca' => 'Acceptar-les totes',
            'gl' => 'Aceptar todas', 'eu' => 'Onartu guztiak', 'fr' => 'Tout accepter',
            'pt' => 'Aceitar todas',
        ],
        'cookies.reject' => [
            'es' => 'Rechazar opcionales', 'en' => 'Reject optional', 'ca' => 'Rebutjar les opcionals',
            'gl' => 'Rexeitar opcionais', 'eu' => 'Baztertu aukerakoak', 'fr' => 'Refuser les facultatifs',
            'pt' => 'Rejeitar opcionais',
        ],
        'cookies.configure' => [
            'es' => 'Configurar', 'en' => 'Customise', 'ca' => 'Configurar',
            'gl' => 'Configurar', 'eu' => 'Konfiguratu', 'fr' => 'Personnaliser',
            'pt' => 'Configurar',
        ],
        'cookies.save' => [
            'es' => 'Guardar elección', 'en' => 'Save choice', 'ca' => 'Desar l\'elecció',
            'gl' => 'Gardar a escolla', 'eu' => 'Gorde hautua', 'fr' => 'Enregistrer mon choix',
            'pt' => 'Guardar escolha',
        ],
        'cookies.modal_title' => [
            'es' => 'Configurar cookies', 'en' => 'Cookie settings', 'ca' => 'Configurar les cookies',
            'gl' => 'Configurar cookies', 'eu' => 'Cookieen ezarpenak', 'fr' => 'Paramètres des cookies',
            'pt' => 'Configurar cookies',
        ],
        'cookies.reopen' => [
            'es' => 'Configurar cookies', 'en' => 'Cookie settings', 'ca' => 'Configurar les cookies',
            'gl' => 'Configurar cookies', 'eu' => 'Cookieen ezarpenak', 'fr' => 'Paramètres des cookies',
            'pt' => 'Configurar cookies',
        ],
        'cookies.close' => [
            'es' => 'Cerrar', 'en' => 'Close', 'ca' => 'Tancar',
            'gl' => 'Pechar', 'eu' => 'Itxi', 'fr' => 'Fermer',
            'pt' => 'Fechar',
        ],
        'cookies.always_on' => [
            'es' => 'siempre activas', 'en' => 'always on', 'ca' => 'sempre actives',
            'gl' => 'sempre activas', 'eu' => 'beti aktibo', 'fr' => 'toujours actifs',
            'pt' => 'sempre ativos',
        ],
        'cookies.cat.necessary' => [
            'es' => 'Necesarias', 'en' => 'Necessary', 'ca' => 'Necessàries',
            'gl' => 'Necesarias', 'eu' => 'Beharrezkoak', 'fr' => 'Nécessaires',
            'pt' => 'Necessários',
        ],
        'cookies.cat.necessary_desc' => [
            'es' => 'Imprescindibles para que la web funcione. No se pueden desactivar.',
            'en' => 'Essential for the site to work. They cannot be disabled.',
            'ca' => 'Imprescindibles perquè el web funcioni. No es poden desactivar.',
            'gl' => 'Imprescindibles para que a web funcione. Non se poden desactivar.',
            'eu' => 'Webgunea funtzionatzeko ezinbestekoak. Ezin dira desaktibatu.',
            'fr' => 'Indispensables au fonctionnement du site. Ils ne peuvent pas être désactivés.',
            'pt' => 'Imprescindíveis para que o site funcione. Não podem ser desativados.',
        ],
        'cookies.cat.analytics' => [
            'es' => 'Analítica', 'en' => 'Analytics', 'ca' => 'Analítica',
            'gl' => 'Analítica', 'eu' => 'Analitika', 'fr' => 'Mesure d\'audience',
            'pt' => 'Análise',
        ],
        'cookies.cat.analytics_desc' => [
            'es' => 'Nos ayudan a entender cómo se usa la web para mejorarla.',
            'en' => 'They help us understand how the site is used so we can improve it.',
            'ca' => 'Ens ajuden a entendre com es fa servir el web per millorar-lo.',
            'gl' => 'Axúdannos a entender como se usa a web para mellorala.',
            'eu' => 'Webgunea nola erabiltzen den ulertzen laguntzen digute, hobetzeko.',
            'fr' => 'Ils nous aident à comprendre comment le site est utilisé afin de l\'améliorer.',
            'pt' => 'Ajudam-nos a perceber como o site é usado para o melhorarmos.',
        ],
        'cookies.cat.advertising' => [
            'es' => 'Marketing', 'en' => 'Marketing', 'ca' => 'Màrqueting',
            'gl' => 'Marketing', 'eu' => 'Marketina', 'fr' => 'Marketing',
            'pt' => 'Marketing',
        ],
        'cookies.cat.advertising_desc' => [
            'es' => 'Permiten mostrar anuncios relevantes y medir su eficacia.',
            'en' => 'They allow relevant ads to be shown and their effectiveness measured.',
            'ca' => 'Permeten mostrar anuncis rellevants i mesurar-ne l\'eficàcia.',
            'gl' => 'Permiten amosar anuncios relevantes e medir a súa eficacia.',
            'eu' => 'Iragarki egokiak erakustea eta haien eraginkortasuna neurtzea ahalbidetzen dute.',
            'fr' => 'Ils permettent d\'afficher des publicités pertinentes et d\'en mesurer l\'efficacité.',
            'pt' => 'Permitem mostrar anúncios relevantes e medir a sua eficácia.',
        ],
        'cookies.cat.external_media' => [
            'es' => 'Multimedia externa', 'en' => 'External media', 'ca' => 'Multimèdia externa',
            'gl' => 'Multimedia externa', 'eu' => 'Kanpoko multimedia', 'fr' => 'Médias externes',
            'pt' => 'Multimédia externa',
        ],
        'cookies.cat.external_media_desc' => [
            'es' => 'Vídeos y mapas embebidos (YouTube, Vimeo, Google Maps).',
            'en' => 'Embedded videos and maps (YouTube, Vimeo, Google Maps).',
            'ca' => 'Vídeos i mapes incrustats (YouTube, Vimeo, Google Maps).',
            'gl' => 'Vídeos e mapas incrustados (YouTube, Vimeo, Google Maps).',
            'eu' => 'Kapsulatutako bideoak eta mapak (YouTube, Vimeo, Google Maps).',
            'fr' => 'Vidéos et cartes intégrées (YouTube, Vimeo, Google Maps).',
            'pt' => 'Vídeos e mapas incorporados (YouTube, Vimeo, Google Maps).',
        ],

        // --- Títulos de página por defecto (alimentan el menú automático) ----
        // Solo se usan como plan de reserva cuando la IA no propone estructura.
        'page.home' => [
            'es' => 'Inicio', 'en' => 'Home', 'ca' => 'Inici',
            'gl' => 'Inicio', 'eu' => 'Hasiera', 'fr' => 'Accueil',
            'pt' => 'Início',
        ],
        // Título de emergencia cuando la IA no devuelve uno: es contenido del
        // SITIO (lo lee el visitante), así que va aquí y no en el catálogo del
        // panel — el idioma que manda es el de la web, no el del gestor.
        'page.untitled' => [
            'es' => 'Página sin título', 'en' => 'Untitled page', 'ca' => 'Pàgina sense títol',
            'gl' => 'Páxina sen título', 'eu' => 'Izenbururik gabeko orria', 'fr' => 'Page sans titre',
            'pt' => 'Página sem título',
        ],
        'page.services' => [
            'es' => 'Servicios', 'en' => 'Services', 'ca' => 'Serveis',
            'gl' => 'Servizos', 'eu' => 'Zerbitzuak', 'fr' => 'Services',
            'pt' => 'Serviços',
        ],
        'page.about_us' => [
            'es' => 'Sobre nosotros', 'en' => 'About us', 'ca' => 'Sobre nosaltres',
            'gl' => 'Sobre nós', 'eu' => 'Gu nor garen', 'fr' => 'À propos',
            'pt' => 'Sobre nós',
        ],
        'page.about_me' => [
            'es' => 'Sobre mí', 'en' => 'About me', 'ca' => 'Sobre mi',
            'gl' => 'Sobre min', 'eu' => 'Niri buruz', 'fr' => 'À propos de moi',
            'pt' => 'Sobre mim',
        ],
        'page.contact' => [
            'es' => 'Contacto', 'en' => 'Contact', 'ca' => 'Contacte',
            'gl' => 'Contacto', 'eu' => 'Harremana', 'fr' => 'Contact',
            'pt' => 'Contacto',
        ],
        'page.blog' => [
            'es' => 'Blog', 'en' => 'Blog', 'ca' => 'Blog',
            'gl' => 'Blog', 'eu' => 'Bloga', 'fr' => 'Blog',
            'pt' => 'Blog',
        ],
        'page.portfolio' => [
            'es' => 'Portfolio', 'en' => 'Portfolio', 'ca' => 'Portfolio',
            'gl' => 'Portfolio', 'eu' => 'Portfolioa', 'fr' => 'Portfolio',
            'pt' => 'Portefólio',
        ],
        'page.pricing' => [
            'es' => 'Precios', 'en' => 'Pricing', 'ca' => 'Preus',
            'gl' => 'Prezos', 'eu' => 'Prezioak', 'fr' => 'Tarifs',
            'pt' => 'Preços',
        ],

        // Plantillas manuales de Studio. Aunque se insertan desde el gestor,
        // pasan a ser contenido público y por eso siguen el idioma de página.
        'studio_block.text.label' => [
            'es' => 'Texto', 'en' => 'Text', 'ca' => 'Text', 'gl' => 'Texto',
            'eu' => 'Testua', 'fr' => 'Texte', 'pt' => 'Texto',
        ],
        'studio_block.text.eyebrow' => [
            'es' => 'Destacado', 'en' => 'Featured', 'ca' => 'Destacat', 'gl' => 'Destacado',
            'eu' => 'Nabarmendua', 'fr' => 'À retenir', 'pt' => 'Destaque',
        ],
        'studio_block.text.title' => [
            'es' => 'Una idea para recordar', 'en' => 'An idea worth remembering',
            'ca' => 'Una idea per recordar', 'gl' => 'Unha idea para lembrar',
            'eu' => 'Gogoratzeko ideia bat', 'fr' => 'Une idée à retenir',
            'pt' => 'Uma ideia para recordar',
        ],
        'studio_block.text.body' => [
            'es' => 'Explica aquí el mensaje principal con palabras claras y cercanas.',
            'en' => 'Explain the main message here in clear, approachable words.',
            'ca' => 'Explica aquí el missatge principal amb paraules clares i properes.',
            'gl' => 'Explica aquí a mensaxe principal con palabras claras e próximas.',
            'eu' => 'Azaldu hemen mezu nagusia hitz argi eta hurbilekin.',
            'fr' => 'Expliquez ici le message principal avec des mots clairs et accessibles.',
            'pt' => 'Explique aqui a mensagem principal com palavras claras e próximas.',
        ],
        'studio_block.text_image.label' => [
            'es' => 'Texto e imagen', 'en' => 'Text and image', 'ca' => 'Text i imatge',
            'gl' => 'Texto e imaxe', 'eu' => 'Testua eta irudia', 'fr' => 'Texte et image',
            'pt' => 'Texto e imagem',
        ],
        'studio_block.text_image.eyebrow' => [
            'es' => 'En detalle', 'en' => 'In detail', 'ca' => 'En detall', 'gl' => 'En detalle',
            'eu' => 'Xehetasunez', 'fr' => 'En détail', 'pt' => 'Em detalhe',
        ],
        'studio_block.text_image.title' => [
            'es' => 'Cuenta tu historia con una imagen', 'en' => 'Tell your story with an image',
            'ca' => 'Explica la teva història amb una imatge', 'gl' => 'Conta a túa historia cunha imaxe',
            'eu' => 'Kontatu zure istorioa irudi batekin', 'fr' => 'Racontez votre histoire avec une image',
            'pt' => 'Conte a sua história com uma imagem',
        ],
        'studio_block.text_image.body' => [
            'es' => 'Combina un texto breve con una imagen que ayude a entenderlo de un vistazo.',
            'en' => 'Pair a short text with an image that makes it easy to understand at a glance.',
            'ca' => 'Combina un text breu amb una imatge que ajudi a entendre’l d’un cop d’ull.',
            'gl' => 'Combina un texto breve cunha imaxe que axude a entendelo dunha ollada.',
            'eu' => 'Lotu testu labur bat begiratu batean ulertzen laguntzen duen irudi batekin.',
            'fr' => 'Associez un texte court à une image qui permet de le comprendre en un coup d’œil.',
            'pt' => 'Combine um texto breve com uma imagem que ajude a compreendê-lo de imediato.',
        ],
        'studio_block.text_image.alt' => [
            'es' => 'Imagen relacionada con este contenido', 'en' => 'A visual related to this content',
            'ca' => 'Imatge relacionada amb aquest contingut', 'gl' => 'Imaxe relacionada con este contido',
            'eu' => 'Eduki honekin lotutako irudia', 'fr' => 'Image liée à ce contenu',
            'pt' => 'Imagem relacionada com este conteúdo',
        ],
        'studio_block.cta.label' => [
            'es' => 'Llamada a la acción', 'en' => 'Call to action', 'ca' => 'Crida a l’acció',
            'gl' => 'Chamada á acción', 'eu' => 'Ekintzarako deia', 'fr' => 'Appel à l’action',
            'pt' => 'Chamada para ação',
        ],
        'studio_block.cta.title' => [
            'es' => 'Da el siguiente paso', 'en' => 'Take the next step', 'ca' => 'Fes el pas següent',
            'gl' => 'Da o seguinte paso', 'eu' => 'Eman hurrengo pausoa',
            'fr' => 'Passez à l’étape suivante', 'pt' => 'Dê o próximo passo',
        ],
        'studio_block.cta.body' => [
            'es' => 'Ayuda a tus visitantes a saber qué pueden hacer ahora.',
            'en' => 'Help your visitors understand what they can do next.',
            'ca' => 'Ajuda els visitants a saber què poden fer ara.',
            'gl' => 'Axuda as visitas a saber que poden facer agora.',
            'eu' => 'Lagundu bisitariei orain zer egin dezaketen ulertzen.',
            'fr' => 'Aidez vos visiteurs à comprendre ce qu’ils peuvent faire maintenant.',
            'pt' => 'Ajude os visitantes a perceber o que podem fazer agora.',
        ],
        'studio_block.cta.button' => [
            'es' => 'Descubrir más', 'en' => 'Learn more', 'ca' => 'Descobrir-ne més',
            'gl' => 'Saber máis', 'eu' => 'Gehiago jakin', 'fr' => 'En savoir plus',
            'pt' => 'Saber mais',
        ],

        // ===================================================================
        // Módulo Recursos — catálogo y ficha públicos
        // ===================================================================
        'resources.title' => [
            'es' => 'Recursos', 'en' => 'Resources', 'ca' => 'Recursos', 'gl' => 'Recursos',
            'eu' => 'Baliabideak', 'fr' => 'Ressources', 'pt' => 'Recursos',
        ],
        'resources.eyebrow' => [
            'es' => 'Biblioteca', 'en' => 'Library', 'ca' => 'Biblioteca', 'gl' => 'Biblioteca',
            'eu' => 'Liburutegia', 'fr' => 'Bibliothèque', 'pt' => 'Biblioteca',
        ],
        'resources.intro' => [
            'es' => 'Guías y ebooks para consultar a tu ritmo.', 'en' => 'Guides and ebooks to explore at your own pace.',
            'ca' => 'Guies i ebooks per consultar al teu ritme.', 'gl' => 'Guías e ebooks para consultar ao teu ritmo.',
            'eu' => 'Zure erritmoan kontsultatzeko gidak eta ebookak.', 'fr' => 'Guides et ebooks à consulter à votre rythme.',
            'pt' => 'Guias e ebooks para consultar ao seu ritmo.',
        ],
        'resources.empty' => [
            'es' => 'Aún no hay recursos disponibles.', 'en' => 'There are no resources available yet.',
            'ca' => 'Encara no hi ha recursos disponibles.', 'gl' => 'Aínda non hai recursos dispoñibles.',
            'eu' => 'Oraindik ez dago baliabiderik eskuragarri.', 'fr' => "Aucune ressource n’est encore disponible.",
            'pt' => 'Ainda não há recursos disponíveis.',
        ],
        'resources.view' => [
            'es' => 'Ver recurso', 'en' => 'View resource', 'ca' => 'Veure recurs', 'gl' => 'Ver recurso',
            'eu' => 'Ikusi baliabidea', 'fr' => 'Voir la ressource', 'pt' => 'Ver recurso',
        ],
        'resources.back' => [
            'es' => 'Volver a recursos', 'en' => 'Back to resources', 'ca' => 'Tornar als recursos',
            'gl' => 'Volver aos recursos', 'eu' => 'Itzuli baliabideetara', 'fr' => 'Retour aux ressources',
            'pt' => 'Voltar aos recursos',
        ],
        'resources.download' => [
            'es' => 'Descargar ahora', 'en' => 'Download now', 'ca' => 'Descarregar ara', 'gl' => 'Descargar agora',
            'eu' => 'Deskargatu orain', 'fr' => 'Télécharger', 'pt' => 'Descarregar agora',
        ],
        'resources.form_required' => [
            'es' => 'Completa el formulario para acceder a la descarga.',
            'en' => 'Complete the form to access the download.',
            'ca' => 'Completa el formulari per accedir a la descàrrega.',
            'gl' => 'Completa o formulario para acceder á descarga.',
            'eu' => 'Bete formularioa deskarga eskuratzeko.',
            'fr' => 'Remplissez le formulaire pour accéder au téléchargement.',
            'pt' => 'Preencha o formulário para aceder à descarga.',
        ],
        'resources.download_ready' => [
            'es' => 'Descargar de nuevo', 'en' => 'Download again', 'ca' => 'Tornar a descarregar',
            'gl' => 'Descargar de novo', 'eu' => 'Deskargatu berriro', 'fr' => 'Télécharger à nouveau',
            'pt' => 'Descarregar novamente',
        ],
        'resources.unavailable' => [
            'es' => 'Este recurso no está disponible temporalmente.',
            'en' => 'This resource is temporarily unavailable.',
            'ca' => 'Aquest recurs no està disponible temporalment.',
            'gl' => 'Este recurso non está dispoñible temporalmente.',
            'eu' => 'Baliabide hau ez dago erabilgarri aldi baterako.',
            'fr' => 'Cette ressource est temporairement indisponible.',
            'pt' => 'Este recurso está temporariamente indisponível.',
        ],
        'resources.file' => [
            'es' => 'Archivo {format} · {size}', 'en' => '{format} file · {size}',
            'ca' => 'Fitxer {format} · {size}', 'gl' => 'Arquivo {format} · {size}',
            'eu' => '{format} fitxategia · {size}', 'fr' => 'Fichier {format} · {size}',
            'pt' => 'Ficheiro {format} · {size}',
        ],

        // ===================================================================
        // Módulo Commerce — escaparate público (I18N-FULL T0.1)
        // Solo los idiomas de MODULE_LANGUAGES; el resto cae a castellano.
        // ===================================================================
        'shop.title' => [
            'es' => 'Tienda', 'en' => 'Shop', 'ca' => 'Botiga',
            'gl' => 'Tenda', 'fr' => 'Boutique', 'pt' => 'Loja',
        ],
        'shop.no_image' => [
            'es' => 'Sin imagen', 'en' => 'No image', 'ca' => 'Sense imatge',
            'gl' => 'Sen imaxe', 'fr' => 'Sans image', 'pt' => 'Sem imagem',
        ],
        'shop.sold_out' => [
            'es' => 'Agotado', 'en' => 'Sold out', 'ca' => 'Exhaurit',
            'gl' => 'Esgotado', 'fr' => 'Épuisé', 'pt' => 'Esgotado',
        ],
        'shop.empty_catalog' => [
            'es' => 'Todavía no hay productos a la venta. Vuelve pronto.',
            'en' => 'There are no products on sale yet. Come back soon.',
            'ca' => 'Encara no hi ha productes a la venda. Torna aviat.',
            'gl' => 'Aínda non hai produtos á venda. Volve pronto.',
            'fr' => "Aucun produit n'est encore en vente. Revenez bientôt.",
            'pt' => 'Ainda não há produtos à venda. Volte em breve.',
        ],
        'shop.tax_included' => [
            'es' => 'IVA ({rate}%) incluido', 'en' => 'VAT ({rate}%) included',
            'ca' => 'IVA ({rate}%) inclòs', 'gl' => 'IVE ({rate}%) incluído',
            'fr' => 'TVA ({rate} %) incluse', 'pt' => 'IVA ({rate}%) incluído',
        ],
        'shop.tax_excluded' => [
            'es' => 'Más {rate}% de IVA', 'en' => 'Plus {rate}% VAT',
            'ca' => "Més {rate}% d'IVA", 'gl' => 'Máis {rate}% de IVE',
            'fr' => 'Plus {rate} % de TVA', 'pt' => 'Mais {rate}% de IVA',
        ],
        'shop.quantity' => [
            'es' => 'Cantidad', 'en' => 'Quantity', 'ca' => 'Quantitat',
            'gl' => 'Cantidade', 'fr' => 'Quantité', 'pt' => 'Quantidade',
        ],
        'shop.add_to_cart' => [
            'es' => 'Añadir al carrito', 'en' => 'Add to cart', 'ca' => 'Afegeix a la cistella',
            'gl' => 'Engadir ao carriño', 'fr' => 'Ajouter au panier', 'pt' => 'Adicionar ao carrinho',
        ],
        'shop.stock_left' => [
            'es' => 'Quedan {n} unidades.', 'en' => '{n} units left.',
            'ca' => 'Queden {n} unitats.', 'gl' => 'Quedan {n} unidades.',
            'fr' => 'Il reste {n} unités.', 'pt' => 'Restam {n} unidades.',
        ],
        'shop.cart' => [
            'es' => 'Carrito', 'en' => 'Cart', 'ca' => 'Cistella',
            'gl' => 'Carriño', 'fr' => 'Panier', 'pt' => 'Carrinho',
        ],
        'shop.your_cart' => [
            'es' => 'Tu carrito', 'en' => 'Your cart', 'ca' => 'La teva cistella',
            'gl' => 'O teu carriño', 'fr' => 'Votre panier', 'pt' => 'O seu carrinho',
        ],
        'shop.cart_empty' => [
            'es' => 'El carrito está vacío.', 'en' => 'Your cart is empty.',
            'ca' => 'La cistella és buida.', 'gl' => 'O carriño está baleiro.',
            'fr' => 'Votre panier est vide.', 'pt' => 'O carrinho está vazio.',
        ],
        'shop.view_shop' => [
            'es' => 'Ver la tienda', 'en' => 'Browse the shop', 'ca' => 'Veure la botiga',
            'gl' => 'Ver a tenda', 'fr' => 'Voir la boutique', 'pt' => 'Ver a loja',
        ],
        'shop.col_product' => [
            'es' => 'Producto', 'en' => 'Product', 'ca' => 'Producte',
            'gl' => 'Produto', 'fr' => 'Produit', 'pt' => 'Produto',
        ],
        'shop.col_price' => [
            'es' => 'Precio', 'en' => 'Price', 'ca' => 'Preu',
            'gl' => 'Prezo', 'fr' => 'Prix', 'pt' => 'Preço',
        ],
        'shop.remove' => [
            'es' => 'Quitar', 'en' => 'Remove', 'ca' => 'Treure',
            'gl' => 'Quitar', 'fr' => 'Retirer', 'pt' => 'Remover',
        ],
        'shop.update_cart' => [
            'es' => 'Actualizar carrito', 'en' => 'Update cart', 'ca' => 'Actualitza la cistella',
            'gl' => 'Actualizar carriño', 'fr' => 'Mettre à jour le panier', 'pt' => 'Atualizar carrinho',
        ],
        'shop.checkout' => [
            'es' => 'Finalizar compra', 'en' => 'Checkout', 'ca' => 'Finalitza la compra',
            'gl' => 'Finalizar a compra', 'fr' => 'Finaliser la commande', 'pt' => 'Finalizar compra',
        ],
        'shop.subtotal' => [
            'es' => 'Subtotal', 'en' => 'Subtotal', 'ca' => 'Subtotal',
            'gl' => 'Subtotal', 'fr' => 'Sous-total', 'pt' => 'Subtotal',
        ],
        'shop.shipping' => [
            'es' => 'Envío', 'en' => 'Shipping', 'ca' => 'Enviament',
            'gl' => 'Envío', 'fr' => 'Livraison', 'pt' => 'Envio',
        ],
        'shop.total' => [
            'es' => 'Total', 'en' => 'Total', 'ca' => 'Total',
            'gl' => 'Total', 'fr' => 'Total', 'pt' => 'Total',
        ],
        'shop.tax_line' => [
            'es' => 'Incluye IVA', 'en' => 'VAT included', 'ca' => 'Inclou IVA',
            'gl' => 'Inclúe IVE', 'fr' => 'TVA incluse', 'pt' => 'Inclui IVA',
        ],
        'shop.your_details' => [
            'es' => 'Tus datos', 'en' => 'Your details', 'ca' => 'Les teves dades',
            'gl' => 'Os teus datos', 'fr' => 'Vos coordonnées', 'pt' => 'Os seus dados',
        ],
        'shop.field_name' => [
            'es' => 'Nombre y apellidos', 'en' => 'Full name', 'ca' => 'Nom i cognoms',
            'gl' => 'Nome e apelidos', 'fr' => 'Nom et prénom', 'pt' => 'Nome completo',
        ],
        'shop.field_email' => [
            'es' => 'Email', 'en' => 'Email', 'ca' => 'Correu electrònic',
            'gl' => 'Correo electrónico', 'fr' => 'E-mail', 'pt' => 'E-mail',
        ],
        'shop.field_phone' => [
            'es' => 'Teléfono', 'en' => 'Phone', 'ca' => 'Telèfon',
            'gl' => 'Teléfono', 'fr' => 'Téléphone', 'pt' => 'Telefone',
        ],
        'shop.shipping_address' => [
            'es' => 'Dirección de envío', 'en' => 'Shipping address', 'ca' => "Adreça d'enviament",
            'gl' => 'Enderezo de envío', 'fr' => 'Adresse de livraison', 'pt' => 'Morada de envio',
        ],
        'shop.field_address' => [
            'es' => 'Dirección', 'en' => 'Address', 'ca' => 'Adreça',
            'gl' => 'Enderezo', 'fr' => 'Adresse', 'pt' => 'Morada',
        ],
        'shop.field_city' => [
            'es' => 'Población', 'en' => 'City', 'ca' => 'Població',
            'gl' => 'Localidade', 'fr' => 'Ville', 'pt' => 'Localidade',
        ],
        'shop.field_postcode' => [
            'es' => 'Código postal', 'en' => 'Postcode', 'ca' => 'Codi postal',
            'gl' => 'Código postal', 'fr' => 'Code postal', 'pt' => 'Código postal',
        ],
        'shop.field_province' => [
            'es' => 'Provincia', 'en' => 'Province', 'ca' => 'Província',
            'gl' => 'Provincia', 'fr' => 'Région', 'pt' => 'Distrito',
        ],
        'shop.field_notes' => [
            'es' => 'Notas del pedido', 'en' => 'Order notes', 'ca' => 'Notes de la comanda',
            'gl' => 'Notas do pedido', 'fr' => 'Notes de commande', 'pt' => 'Notas da encomenda',
        ],
        'shop.payment' => [
            'es' => 'Pago', 'en' => 'Payment', 'ca' => 'Pagament',
            'gl' => 'Pagamento', 'fr' => 'Paiement', 'pt' => 'Pagamento',
        ],
        'shop.place_order' => [
            'es' => 'Realizar pedido', 'en' => 'Place order', 'ca' => 'Fes la comanda',
            'gl' => 'Facer o pedido', 'fr' => 'Passer la commande', 'pt' => 'Finalizar encomenda',
        ],
        'shop.no_payment_methods' => [
            'es' => 'Ahora mismo no hay ningún método de pago disponible.',
            'en' => 'No payment method is available right now.',
            'ca' => 'Ara mateix no hi ha cap mètode de pagament disponible.',
            'gl' => 'Agora mesmo non hai ningún método de pagamento dispoñible.',
            'fr' => "Aucun moyen de paiement n'est disponible pour le moment.",
            'pt' => 'De momento não há nenhum método de pagamento disponível.',
        ],
        'shop.your_order' => [
            'es' => 'Tu pedido', 'en' => 'Your order', 'ca' => 'La teva comanda',
            'gl' => 'O teu pedido', 'fr' => 'Votre commande', 'pt' => 'A sua encomenda',
        ],
        'shop.order' => [
            'es' => 'Pedido', 'en' => 'Order', 'ca' => 'Comanda',
            'gl' => 'Pedido', 'fr' => 'Commande', 'pt' => 'Encomenda',
        ],
        'shop.thanks_title' => [
            'es' => '¡Gracias por tu pedido!', 'en' => 'Thank you for your order!',
            'ca' => 'Gràcies per la teva comanda!', 'gl' => 'Grazas polo teu pedido!',
            'fr' => 'Merci pour votre commande !', 'pt' => 'Obrigado pela sua encomenda!',
        ],
        'shop.thanks_email_sent' => [
            'es' => 'Te hemos enviado un email a {email} con el resumen.',
            'en' => 'We have sent a summary to {email}.',
            'ca' => 'T’hem enviat un correu a {email} amb el resum.',
            'gl' => 'Enviámosche un correo a {email} co resumo.',
            'fr' => 'Nous avons envoyé un récapitulatif à {email}.',
            'pt' => 'Enviámos um resumo para {email}.',
        ],
        'shop.payment_confirmed' => [
            'es' => '✓ Pago confirmado. Estamos preparando tu pedido.',
            'en' => '✓ Payment confirmed. We are preparing your order.',
            'ca' => '✓ Pagament confirmat. Estem preparant la teva comanda.',
            'gl' => '✓ Pagamento confirmado. Estamos a preparar o teu pedido.',
            'fr' => '✓ Paiement confirmé. Nous préparons votre commande.',
            'pt' => '✓ Pagamento confirmado. Estamos a preparar a sua encomenda.',
        ],
        'shop.back_to_shop' => [
            'es' => 'Volver a la tienda', 'en' => 'Back to the shop', 'ca' => 'Torna a la botiga',
            'gl' => 'Volver á tenda', 'fr' => 'Retour à la boutique', 'pt' => 'Voltar à loja',
        ],
        'shop.err_expired' => [
            'es' => 'El checkout llevaba demasiado tiempo abierto. Revisa los datos y vuelve a confirmar.',
            'en' => 'The checkout was open for too long. Please check your details and confirm again.',
            'ca' => 'La compra portava massa estona oberta. Revisa les dades i torna a confirmar.',
            'gl' => 'A compra levaba demasiado tempo aberta. Revisa os datos e confirma de novo.',
            'fr' => 'La commande est restée ouverte trop longtemps. Vérifiez vos informations et confirmez à nouveau.',
            'pt' => 'A compra esteve aberta demasiado tempo. Verifique os dados e confirme novamente.',
        ],
        'shop.err_name' => [
            'es' => 'El nombre es obligatorio.', 'en' => 'Name is required.',
            'ca' => 'El nom és obligatori.', 'gl' => 'O nome é obrigatorio.',
            'fr' => 'Le nom est obligatoire.', 'pt' => 'O nome é obrigatório.',
        ],
        'shop.err_email' => [
            'es' => 'Necesitamos un email válido para el pedido.',
            'en' => 'We need a valid email address for the order.',
            'ca' => 'Necessitem un correu electrònic vàlid per a la comanda.',
            'gl' => 'Necesitamos un correo electrónico válido para o pedido.',
            'fr' => 'Nous avons besoin d’une adresse e-mail valide pour la commande.',
            'pt' => 'Precisamos de um e-mail válido para a encomenda.',
        ],
        'shop.err_address' => [
            'es' => 'Completa la dirección de envío (dirección, población y código postal).',
            'en' => 'Please complete the shipping address (address, city and postcode).',
            'ca' => "Completa l'adreça d'enviament (adreça, població i codi postal).",
            'gl' => 'Completa o enderezo de envío (enderezo, localidade e código postal).',
            'fr' => 'Complétez l’adresse de livraison (adresse, ville et code postal).',
            'pt' => 'Complete a morada de envio (morada, localidade e código postal).',
        ],
        'shop.err_payment_method' => [
            'es' => 'Elige un método de pago.', 'en' => 'Choose a payment method.',
            'ca' => 'Tria un mètode de pagament.', 'gl' => 'Escolle un método de pagamento.',
            'fr' => 'Choisissez un moyen de paiement.', 'pt' => 'Escolha um método de pagamento.',
        ],
        'shop.err_rate_limited' => [
            'es' => 'Hemos recibido varios pedidos seguidos desde tu conexión. Espera unos minutos.',
            'en' => 'We have received several orders in a row from your connection. Please wait a few minutes.',
            'ca' => 'Hem rebut diverses comandes seguides des de la teva connexió. Espera uns minuts.',
            'gl' => 'Recibimos varios pedidos seguidos dende a túa conexión. Agarda uns minutos.',
            'fr' => 'Nous avons reçu plusieurs commandes successives depuis votre connexion. Patientez quelques minutes.',
            'pt' => 'Recebemos várias encomendas seguidas da sua ligação. Aguarde alguns minutos.',
        ],
        'shop.err_out_of_stock' => [
            'es' => 'No queda stock suficiente de «{product}». Revisa el carrito.',
            'en' => 'There is not enough stock of “{product}”. Please review your cart.',
            'ca' => 'No queda prou estoc de «{product}». Revisa la cistella.',
            'gl' => 'Non queda stock abondo de «{product}». Revisa o carriño.',
            'fr' => 'Le stock de « {product} » est insuffisant. Vérifiez votre panier.',
            'pt' => 'Não há stock suficiente de «{product}». Reveja o carrinho.',
        ],
        'shop.err_order_failed' => [
            'es' => 'No se pudo crear el pedido. Revisa el carrito e inténtalo de nuevo.',
            'en' => 'The order could not be created. Please review your cart and try again.',
            'ca' => 'No s’ha pogut crear la comanda. Revisa la cistella i torna-ho a provar.',
            'gl' => 'Non se puido crear o pedido. Revisa o carriño e téntao de novo.',
            'fr' => 'La commande n’a pas pu être créée. Vérifiez votre panier et réessayez.',
            'pt' => 'Não foi possível criar a encomenda. Reveja o carrinho e tente novamente.',
        ],
        // Métodos de pago e instrucciones. OJO: `shop.manual_pending` y
        // `shop.manual_reference` llevan `<strong>` inline y se renderizan SIN
        // escapar (contenido nuestro, nunca del usuario). Son las únicas claves
        // del diccionario con marcado; `tests/commerce_microcopy.php` lo vigila.
        'shop.pay_manual' => [
            'es' => 'Transferencia bancaria o pago acordado',
            'en' => 'Bank transfer or agreed payment',
            'ca' => 'Transferència bancària o pagament acordat',
            'gl' => 'Transferencia bancaria ou pagamento acordado',
            'fr' => 'Virement bancaire ou paiement convenu',
            'pt' => 'Transferência bancária ou pagamento acordado',
        ],
        'shop.pay_stripe' => [
            'es' => 'Tarjeta de crédito o débito (pago seguro con Stripe)',
            'en' => 'Credit or debit card (secure payment with Stripe)',
            'ca' => 'Targeta de crèdit o dèbit (pagament segur amb Stripe)',
            'gl' => 'Tarxeta de crédito ou débito (pagamento seguro con Stripe)',
            'fr' => 'Carte bancaire (paiement sécurisé avec Stripe)',
            'pt' => 'Cartão de crédito ou débito (pagamento seguro com Stripe)',
        ],
        'shop.manual_pending' => [
            'es' => 'Tu pedido queda <strong>pendiente de pago</strong>.',
            'en' => 'Your order is <strong>awaiting payment</strong>.',
            'ca' => 'La teva comanda queda <strong>pendent de pagament</strong>.',
            'gl' => 'O teu pedido queda <strong>pendente de pagamento</strong>.',
            'fr' => 'Votre commande est <strong>en attente de paiement</strong>.',
            'pt' => 'A sua encomenda fica <strong>a aguardar pagamento</strong>.',
        ],
        'shop.manual_contact' => [
            'es' => 'Te contactaremos por email con las instrucciones de pago.',
            'en' => 'We will email you the payment instructions.',
            'ca' => 'Et contactarem per correu amb les instruccions de pagament.',
            'gl' => 'Contactarémoste por correo coas instrucións de pagamento.',
            'fr' => 'Nous vous enverrons les instructions de paiement par e-mail.',
            'pt' => 'Entraremos em contacto por e-mail com as instruções de pagamento.',
        ],
        'shop.manual_reference' => [
            'es' => 'Indica como concepto el número de pedido: <strong>{number}</strong>.',
            'en' => 'Use the order number as the payment reference: <strong>{number}</strong>.',
            'ca' => 'Indica com a concepte el número de comanda: <strong>{number}</strong>.',
            'gl' => 'Indica como concepto o número de pedido: <strong>{number}</strong>.',
            'fr' => 'Indiquez le numéro de commande en référence : <strong>{number}</strong>.',
            'pt' => 'Indique o número da encomenda como referência: <strong>{number}</strong>.',
        ],
        'shop.warn_unavailable' => [
            'es' => 'Ese producto ya no está disponible.', 'en' => 'That product is no longer available.',
            'ca' => 'Aquest producte ja no està disponible.', 'gl' => 'Ese produto xa non está dispoñible.',
            'fr' => "Ce produit n'est plus disponible.", 'pt' => 'Esse produto já não está disponível.',
        ],
        'shop.warn_only_left' => [
            'es' => 'Solo quedan {n} unidades de «{product}».',
            'en' => 'Only {n} units of “{product}” are left.',
            'ca' => 'Només queden {n} unitats de «{product}».',
            'gl' => 'Só quedan {n} unidades de «{product}».',
            'fr' => 'Il ne reste que {n} unités de « {product} ».',
            'pt' => 'Só restam {n} unidades de «{product}».',
        ],
        'shop.warn_sold_out' => [
            'es' => '«{product}» está agotado.', 'en' => '“{product}” is sold out.',
            'ca' => '«{product}» està exhaurit.', 'gl' => '«{product}» está esgotado.',
            'fr' => '« {product} » est épuisé.', 'pt' => '«{product}» está esgotado.',
        ],

        // ===================================================================
        // Módulo Booking — widget, API pública y páginas de cancelación
        // (I18N-FULL T0.2). Las claves `booking.*` que empiezan por los
        // nombres del widget se sirven al JS desde la API.
        // ===================================================================
        'booking.loading' => [
            'es' => 'Cargando disponibilidad…', 'en' => 'Loading availability…',
            'ca' => 'Carregant disponibilitat…', 'gl' => 'Cargando dispoñibilidade…',
            'fr' => 'Chargement des disponibilités…', 'pt' => 'A carregar a disponibilidade…',
        ],
        'booking.no_slots' => [
            'es' => 'Ahora mismo no hay huecos disponibles. Vuelve a intentarlo más adelante.',
            'en' => 'There are no slots available right now. Please try again later.',
            'ca' => 'Ara mateix no hi ha hores disponibles. Torna-ho a provar més endavant.',
            'gl' => 'Agora mesmo non hai ocos dispoñibles. Téntao de novo máis adiante.',
            'fr' => 'Aucun créneau disponible pour le moment. Réessayez plus tard.',
            'pt' => 'Neste momento não há horários disponíveis. Tente novamente mais tarde.',
        ],
        'booking.slots_one' => [
            'es' => '{n} hueco', 'en' => '{n} slot', 'ca' => '{n} hora',
            'gl' => '{n} oco', 'fr' => '{n} créneau', 'pt' => '{n} horário',
        ],
        'booking.slots_many' => [
            'es' => '{n} huecos', 'en' => '{n} slots', 'ca' => '{n} hores',
            'gl' => '{n} ocos', 'fr' => '{n} créneaux', 'pt' => '{n} horários',
        ],
        'booking.ph_name' => [
            'es' => 'Tu nombre *', 'en' => 'Your name *', 'ca' => 'El teu nom *',
            'gl' => 'O teu nome *', 'fr' => 'Votre nom *', 'pt' => 'O seu nome *',
        ],
        'booking.ph_email' => [
            'es' => 'Tu email *', 'en' => 'Your email *', 'ca' => 'El teu correu *',
            'gl' => 'O teu correo *', 'fr' => 'Votre e-mail *', 'pt' => 'O seu e-mail *',
        ],
        'booking.ph_phone' => [
            'es' => 'Teléfono (opcional)', 'en' => 'Phone (optional)', 'ca' => 'Telèfon (opcional)',
            'gl' => 'Teléfono (opcional)', 'fr' => 'Téléphone (facultatif)', 'pt' => 'Telefone (opcional)',
        ],
        'booking.ph_notes' => [
            'es' => 'Notas (opcional)', 'en' => 'Notes (optional)', 'ca' => 'Notes (opcional)',
            'gl' => 'Notas (opcional)', 'fr' => 'Notes (facultatif)', 'pt' => 'Notas (opcional)',
        ],
        'booking.book_at' => [
            'es' => 'Reservar {time}', 'en' => 'Book {time}', 'ca' => 'Reserva {time}',
            'gl' => 'Reservar {time}', 'fr' => 'Réserver à {time}', 'pt' => 'Reservar {time}',
        ],
        // MODULOS M8 — campos configurables del formulario de reserva. Los
        // errores llevan el nombre del campo porque el gestor puede haberle
        // puesto cualquier etiqueta ("Matrícula", "Nº de personas"…).
        'booking.err_field_required' => [
            'es' => '{campo} es obligatorio.', 'en' => '{campo} is required.',
            'ca' => '{campo} és obligatori.', 'gl' => '{campo} é obrigatorio.',
            'fr' => '{campo} est obligatoire.', 'pt' => '{campo} é obrigatório.',
        ],
        'booking.err_field_number' => [
            'es' => '{campo} tiene que ser un número.', 'en' => '{campo} must be a number.',
            'ca' => '{campo} ha de ser un número.', 'gl' => '{campo} ten que ser un número.',
            'fr' => '{campo} doit être un nombre.', 'pt' => '{campo} tem de ser um número.',
        ],
        'booking.err_field_date' => [
            'es' => '{campo} tiene que ser una fecha válida.', 'en' => '{campo} must be a valid date.',
            'ca' => '{campo} ha de ser una data vàlida.', 'gl' => '{campo} ten que ser unha data válida.',
            'fr' => '{campo} doit être une date valide.', 'pt' => '{campo} tem de ser uma data válida.',
        ],
        'booking.err_field_email' => [
            'es' => '{campo} tiene que ser un email válido.', 'en' => '{campo} must be a valid email.',
            'ca' => '{campo} ha de ser un correu vàlid.', 'gl' => '{campo} ten que ser un email válido.',
            'fr' => '{campo} doit être un e-mail valide.', 'pt' => '{campo} tem de ser um email válido.',
        ],
        'booking.err_field_option' => [
            'es' => 'Elige una opción válida en {campo}.', 'en' => 'Choose a valid option in {campo}.',
            'ca' => 'Tria una opció vàlida a {campo}.', 'gl' => 'Escolle unha opción válida en {campo}.',
            'fr' => 'Choisissez une option valide dans {campo}.', 'pt' => 'Escolha uma opção válida em {campo}.',
        ],
        'booking.yes' => [
            'es' => 'Sí', 'en' => 'Yes', 'ca' => 'Sí', 'gl' => 'Si', 'fr' => 'Oui', 'pt' => 'Sim',
        ],
        'booking.no' => [
            'es' => 'No', 'en' => 'No', 'ca' => 'No', 'gl' => 'Non', 'fr' => 'Non', 'pt' => 'Não',
        ],
        'booking.optional' => [
            'es' => '(opcional)', 'en' => '(optional)', 'ca' => '(opcional)',
            'gl' => '(opcional)', 'fr' => '(facultatif)', 'pt' => '(opcional)',
        ],
        // Los nombres pelados de teléfono y notas. `booking.ph_*` los lleva con
        // "(opcional)" pegado, y desde M8 el gestor puede hacerlos obligatorios:
        // la etiqueta se compone según cómo estén configurados.
        'booking.field_phone' => [
            'es' => 'Teléfono', 'en' => 'Phone', 'ca' => 'Telèfon',
            'gl' => 'Teléfono', 'fr' => 'Téléphone', 'pt' => 'Telefone',
        ],
        'booking.field_notes' => [
            'es' => 'Notas', 'en' => 'Notes', 'ca' => 'Notes',
            'gl' => 'Notas', 'fr' => 'Notes', 'pt' => 'Notas',
        ],
        'booking.sent_title' => [
            'es' => '¡Reserva enviada!', 'en' => 'Booking sent!', 'ca' => 'Reserva enviada!',
            'gl' => 'Reserva enviada!', 'fr' => 'Réservation envoyée !', 'pt' => 'Reserva enviada!',
        ],
        'booking.registered' => [
            'es' => 'Reserva registrada.', 'en' => 'Booking registered.', 'ca' => 'Reserva registrada.',
            'gl' => 'Reserva rexistrada.', 'fr' => 'Réservation enregistrée.', 'pt' => 'Reserva registada.',
        ],
        'booking.slot_taken' => [
            'es' => 'Ese hueco se acaba de ocupar. Elige otro, por favor.',
            'en' => 'That slot has just been taken. Please choose another one.',
            'ca' => 'Aquesta hora acaba de ser ocupada. Tria’n una altra, si us plau.',
            'gl' => 'Ese oco acaba de ocuparse. Escolle outro, por favor.',
            'fr' => 'Ce créneau vient d’être réservé. Choisissez-en un autre, s’il vous plaît.',
            'pt' => 'Esse horário acabou de ser ocupado. Escolha outro, por favor.',
        ],
        'booking.too_many' => [
            'es' => 'Demasiados intentos seguidos. Espera unos minutos.',
            'en' => 'Too many attempts in a row. Please wait a few minutes.',
            'ca' => 'Massa intents seguits. Espera uns minuts.',
            'gl' => 'Demasiados intentos seguidos. Agarda uns minutos.',
            'fr' => 'Trop de tentatives successives. Patientez quelques minutes.',
            'pt' => 'Demasiadas tentativas seguidas. Aguarde alguns minutos.',
        ],
        'booking.failed' => [
            'es' => 'No se pudo completar la reserva. Revisa los datos.',
            'en' => 'The booking could not be completed. Please check your details.',
            'ca' => 'No s’ha pogut completar la reserva. Revisa les dades.',
            'gl' => 'Non se puido completar a reserva. Revisa os datos.',
            'fr' => 'La réservation n’a pas pu aboutir. Vérifiez vos informations.',
            'pt' => 'Não foi possível concluir a reserva. Verifique os dados.',
        ],
        'booking.network' => [
            'es' => 'Error de conexión. Inténtalo de nuevo.',
            'en' => 'Connection error. Please try again.',
            'ca' => 'Error de connexió. Torna-ho a provar.',
            'gl' => 'Erro de conexión. Téntao de novo.',
            'fr' => 'Erreur de connexion. Réessayez.',
            'pt' => 'Erro de ligação. Tente novamente.',
        ],
        'booking.load_failed' => [
            'es' => 'No se pudo cargar la disponibilidad.', 'en' => 'Availability could not be loaded.',
            'ca' => 'No s’ha pogut carregar la disponibilitat.', 'gl' => 'Non se puido cargar a dispoñibilidade.',
            'fr' => 'Impossible de charger les disponibilités.', 'pt' => 'Não foi possível carregar a disponibilidade.',
        ],
        'booking.service_unavailable' => [
            'es' => 'Este servicio no está disponible.', 'en' => 'This service is not available.',
            'ca' => 'Aquest servei no està disponible.', 'gl' => 'Este servizo non está dispoñible.',
            'fr' => 'Ce service n’est pas disponible.', 'pt' => 'Este serviço não está disponível.',
        ],
        'booking.local_time' => [
            'es' => 'Horario local: {tz}', 'en' => 'Local time: {tz}', 'ca' => 'Horari local: {tz}',
            'gl' => 'Horario local: {tz}', 'fr' => 'Heure locale : {tz}', 'pt' => 'Hora local: {tz}',
        ],
        // MODULOS M2 — alternativa del calendario embebido en la propia web
        // cuando el visitante navega sin JavaScript. No la sirve la API: se
        // pinta en el servidor dentro del <noscript>.
        'booking.noscript' => [
            'es' => 'Para reservar desde esta página necesitas activar JavaScript en tu navegador.',
            'en' => 'To book from this page you need to enable JavaScript in your browser.',
            'ca' => 'Per reservar des d\'aquesta pàgina necessites activar JavaScript al navegador.',
            'gl' => 'Para reservar desde esta páxina precisas activar JavaScript no teu navegador.',
            'fr' => 'Pour réserver depuis cette page, vous devez activer JavaScript dans votre navigateur.',
            'pt' => 'Para reservar nesta página precisa de ativar o JavaScript no seu navegador.',
        ],

        // --- API pública: validación y confirmaciones ---
        'booking.err_name' => [
            'es' => 'El nombre es obligatorio.', 'en' => 'Name is required.',
            'ca' => 'El nom és obligatori.', 'gl' => 'O nome é obrigatorio.',
            'fr' => 'Le nom est obligatoire.', 'pt' => 'O nome é obrigatório.',
        ],
        'booking.err_email' => [
            'es' => 'Necesitamos un email válido para confirmar la reserva.',
            'en' => 'We need a valid email address to confirm the booking.',
            'ca' => 'Necessitem un correu electrònic vàlid per confirmar la reserva.',
            'gl' => 'Necesitamos un correo electrónico válido para confirmar a reserva.',
            'fr' => 'Nous avons besoin d’une adresse e-mail valide pour confirmer la réservation.',
            'pt' => 'Precisamos de um e-mail válido para confirmar a reserva.',
        ],
        'booking.err_start' => [
            'es' => 'Falta el servicio o la hora de inicio.', 'en' => 'The service or start time is missing.',
            'ca' => 'Falta el servei o l’hora d’inici.', 'gl' => 'Falta o servizo ou a hora de inicio.',
            'fr' => 'Le service ou l’heure de début est manquant.', 'pt' => 'Falta o serviço ou a hora de início.',
        ],
        'booking.created_confirmed' => [
            'es' => 'Reserva confirmada. Te hemos enviado un email con los detalles.',
            'en' => 'Booking confirmed. We have emailed you the details.',
            'ca' => 'Reserva confirmada. T’hem enviat un correu amb els detalls.',
            'gl' => 'Reserva confirmada. Enviámosche un correo cos detalles.',
            'fr' => 'Réservation confirmée. Nous vous avons envoyé les détails par e-mail.',
            'pt' => 'Reserva confirmada. Enviámos-lhe um e-mail com os detalhes.',
        ],
        'booking.created_pending' => [
            'es' => 'Reserva recibida, pendiente de confirmación. Te avisaremos por email.',
            'en' => 'Booking received, awaiting confirmation. We will let you know by email.',
            'ca' => 'Reserva rebuda, pendent de confirmació. T’avisarem per correu.',
            'gl' => 'Reserva recibida, pendente de confirmación. Avisarémoste por correo.',
            'fr' => 'Réservation reçue, en attente de confirmation. Nous vous préviendrons par e-mail.',
            'pt' => 'Reserva recebida, a aguardar confirmação. Avisaremos por e-mail.',
        ],
        'booking.email_sent' => [
            'es' => 'Te hemos enviado un email con los detalles.',
            'en' => 'We have emailed you the details.',
            'ca' => 'T’hem enviat un correu amb els detalls.',
            'gl' => 'Enviámosche un correo cos detalles.',
            'fr' => 'Nous vous avons envoyé les détails par e-mail.',
            'pt' => 'Enviámos-lhe um e-mail com os detalhes.',
        ],

        // --- Páginas de cancelación (se abren desde el email) ---
        'booking.fallback_service' => [
            'es' => 'Reserva', 'en' => 'Booking', 'ca' => 'Reserva',
            'gl' => 'Reserva', 'fr' => 'Réservation', 'pt' => 'Reserva',
        ],
        'booking.cancel_already_title' => [
            'es' => 'Reserva ya cancelada', 'en' => 'Booking already cancelled',
            'ca' => 'Reserva ja cancel·lada', 'gl' => 'Reserva xa cancelada',
            'fr' => 'Réservation déjà annulée', 'pt' => 'Reserva já cancelada',
        ],
        'booking.cancel_already_body' => [
            'es' => 'Esta reserva ya estaba cancelada. No tienes que hacer nada más.',
            'en' => 'This booking was already cancelled. There is nothing else to do.',
            'ca' => 'Aquesta reserva ja estava cancel·lada. No has de fer res més.',
            'gl' => 'Esta reserva xa estaba cancelada. Non tes que facer nada máis.',
            'fr' => 'Cette réservation était déjà annulée. Vous n’avez rien d’autre à faire.',
            'pt' => 'Esta reserva já estava cancelada. Não precisa de fazer mais nada.',
        ],
        'booking.cancel_title' => [
            'es' => 'Cancelar reserva', 'en' => 'Cancel booking', 'ca' => 'Cancel·lar la reserva',
            'gl' => 'Cancelar reserva', 'fr' => 'Annuler la réservation', 'pt' => 'Cancelar reserva',
        ],
        'booking.cancel_confirm' => [
            'es' => '¿Seguro que quieres cancelar esta reserva?',
            'en' => 'Are you sure you want to cancel this booking?',
            'ca' => 'Segur que vols cancel·lar aquesta reserva?',
            'gl' => 'Seguro que queres cancelar esta reserva?',
            'fr' => 'Voulez-vous vraiment annuler cette réservation ?',
            'pt' => 'Tem a certeza de que quer cancelar esta reserva?',
        ],
        'booking.cancel_button' => [
            'es' => 'Sí, cancelar la reserva', 'en' => 'Yes, cancel the booking',
            'ca' => 'Sí, cancel·la la reserva', 'gl' => 'Si, cancelar a reserva',
            'fr' => 'Oui, annuler la réservation', 'pt' => 'Sim, cancelar a reserva',
        ],
        'booking.cancel_mistake' => [
            'es' => 'Si has llegado aquí por error, simplemente cierra esta página.',
            'en' => 'If you got here by mistake, just close this page.',
            'ca' => 'Si has arribat aquí per error, simplement tanca aquesta pàgina.',
            'gl' => 'Se chegaches aquí por erro, simplemente pecha esta páxina.',
            'fr' => 'Si vous êtes arrivé ici par erreur, fermez simplement cette page.',
            'pt' => 'Se chegou aqui por engano, basta fechar esta página.',
        ],
        'booking.cancelled_title' => [
            'es' => 'Reserva cancelada', 'en' => 'Booking cancelled', 'ca' => 'Reserva cancel·lada',
            'gl' => 'Reserva cancelada', 'fr' => 'Réservation annulée', 'pt' => 'Reserva cancelada',
        ],
        'booking.cancelled_body' => [
            'es' => 'Tu reserva ha quedado cancelada. Gracias por avisar.',
            'en' => 'Your booking has been cancelled. Thanks for letting us know.',
            'ca' => 'La teva reserva ha quedat cancel·lada. Gràcies per avisar.',
            'gl' => 'A túa reserva quedou cancelada. Grazas por avisar.',
            'fr' => 'Votre réservation a été annulée. Merci de nous avoir prévenus.',
            'pt' => 'A sua reserva foi cancelada. Obrigado por avisar.',
        ],
        // ===================================================================
        // Emails transaccionales al CLIENTE (I18N-FULL T0.3).
        // Los avisos al ADMINISTRADOR no están aquí a propósito: siguen en
        // castellano, porque los recibe el dueño del sitio y su panel es
        // castellano.
        // ===================================================================
        'mail.greeting' => [
            'es' => 'Hola {name},', 'en' => 'Hi {name},', 'ca' => 'Hola {name},',
            'gl' => 'Ola {name},', 'fr' => 'Bonjour {name},', 'pt' => 'Olá {name},',
        ],

        // --- Commerce ---
        'mail.shop.created_subject' => [
            'es' => 'Pedido recibido: {number}', 'en' => 'Order received: {number}',
            'ca' => 'Comanda rebuda: {number}', 'gl' => 'Pedido recibido: {number}',
            'fr' => 'Commande reçue : {number}', 'pt' => 'Encomenda recebida: {number}',
        ],
        'mail.shop.created_intro' => [
            'es' => 'Hemos recibido tu pedido {number}. Resumen:',
            'en' => 'We have received your order {number}. Summary:',
            'ca' => 'Hem rebut la teva comanda {number}. Resum:',
            'gl' => 'Recibimos o teu pedido {number}. Resumo:',
            'fr' => 'Nous avons bien reçu votre commande {number}. Récapitulatif :',
            'pt' => 'Recebemos a sua encomenda {number}. Resumo:',
        ],
        'mail.shop.total_with_tax' => [
            'es' => 'Total: {total} (incluye {tax} de IVA)',
            'en' => 'Total: {total} (includes {tax} VAT)',
            'ca' => 'Total: {total} (inclou {tax} d’IVA)',
            'gl' => 'Total: {total} (inclúe {tax} de IVE)',
            'fr' => 'Total : {total} (dont {tax} de TVA)',
            'pt' => 'Total: {total} (inclui {tax} de IVA)',
        ],
        'mail.shop.total_simple' => [
            'es' => 'Total: {total}', 'en' => 'Total: {total}', 'ca' => 'Total: {total}',
            'gl' => 'Total: {total}', 'fr' => 'Total : {total}', 'pt' => 'Total: {total}',
        ],
        'mail.shop.order_line' => [
            'es' => 'Pedido: {number}', 'en' => 'Order: {number}', 'ca' => 'Comanda: {number}',
            'gl' => 'Pedido: {number}', 'fr' => 'Commande : {number}', 'pt' => 'Encomenda: {number}',
        ],
        'mail.shop.will_notify' => [
            'es' => 'Te avisaremos por email cuando el pedido avance.',
            'en' => 'We will email you as your order progresses.',
            'ca' => 'T’avisarem per correu quan la comanda avanci.',
            'gl' => 'Avisarémoste por correo cando o pedido avance.',
            'fr' => 'Nous vous tiendrons informé par e-mail de l’avancement de votre commande.',
            'pt' => 'Avisaremos por e-mail à medida que a encomenda avançar.',
        ],
        'mail.shop.paid_subject' => [
            'es' => 'Pago recibido: pedido {number}', 'en' => 'Payment received: order {number}',
            'ca' => 'Pagament rebut: comanda {number}', 'gl' => 'Pagamento recibido: pedido {number}',
            'fr' => 'Paiement reçu : commande {number}', 'pt' => 'Pagamento recebido: encomenda {number}',
        ],
        'mail.shop.paid_body' => [
            'es' => 'Hemos recibido el pago de tu pedido. Lo estamos preparando.',
            'en' => 'We have received payment for your order. We are preparing it.',
            'ca' => 'Hem rebut el pagament de la teva comanda. L’estem preparant.',
            'gl' => 'Recibimos o pagamento do teu pedido. Estámolo a preparar.',
            'fr' => 'Nous avons reçu le paiement de votre commande. Nous la préparons.',
            'pt' => 'Recebemos o pagamento da sua encomenda. Estamos a prepará-la.',
        ],
        'mail.shop.shipped_subject' => [
            'es' => 'Pedido enviado: {number}', 'en' => 'Order shipped: {number}',
            'ca' => 'Comanda enviada: {number}', 'gl' => 'Pedido enviado: {number}',
            'fr' => 'Commande expédiée : {number}', 'pt' => 'Encomenda enviada: {number}',
        ],
        'mail.shop.shipped_body' => [
            'es' => 'Tu pedido está en camino.', 'en' => 'Your order is on its way.',
            'ca' => 'La teva comanda està en camí.', 'gl' => 'O teu pedido está en camiño.',
            'fr' => 'Votre commande est en route.', 'pt' => 'A sua encomenda está a caminho.',
        ],
        'mail.shop.cancelled_subject' => [
            'es' => 'Pedido cancelado: {number}', 'en' => 'Order cancelled: {number}',
            'ca' => 'Comanda cancel·lada: {number}', 'gl' => 'Pedido cancelado: {number}',
            'fr' => 'Commande annulée : {number}', 'pt' => 'Encomenda cancelada: {number}',
        ],
        'mail.shop.cancelled_body' => [
            'es' => 'Tu pedido ha sido cancelado. Si tienes dudas, responde a este email.',
            'en' => 'Your order has been cancelled. If you have any questions, just reply to this email.',
            'ca' => 'La teva comanda s’ha cancel·lat. Si tens dubtes, respon a aquest correu.',
            'gl' => 'O teu pedido foi cancelado. Se tes dúbidas, responde a este correo.',
            'fr' => 'Votre commande a été annulée. Si vous avez des questions, répondez à cet e-mail.',
            'pt' => 'A sua encomenda foi cancelada. Se tiver dúvidas, responda a este e-mail.',
        ],

        // --- Booking ---
        'mail.booking.confirmed_subject' => [
            'es' => 'Reserva confirmada: {service} — {when}',
            'en' => 'Booking confirmed: {service} — {when}',
            'ca' => 'Reserva confirmada: {service} — {when}',
            'gl' => 'Reserva confirmada: {service} — {when}',
            'fr' => 'Réservation confirmée : {service} — {when}',
            'pt' => 'Reserva confirmada: {service} — {when}',
        ],
        'mail.booking.received_subject' => [
            'es' => 'Hemos recibido tu reserva: {service} — {when}',
            'en' => 'We have received your booking: {service} — {when}',
            'ca' => 'Hem rebut la teva reserva: {service} — {when}',
            'gl' => 'Recibimos a túa reserva: {service} — {when}',
            'fr' => 'Nous avons bien reçu votre réservation : {service} — {when}',
            'pt' => 'Recebemos a sua reserva: {service} — {when}',
        ],
        'mail.booking.confirmed_intro' => [
            'es' => 'Tu reserva está confirmada. Aquí tienes los detalles:',
            'en' => 'Your booking is confirmed. Here are the details:',
            'ca' => 'La teva reserva està confirmada. Aquí tens els detalls:',
            'gl' => 'A túa reserva está confirmada. Aquí tes os detalles:',
            'fr' => 'Votre réservation est confirmée. Voici les détails :',
            'pt' => 'A sua reserva está confirmada. Aqui estão os detalhes:',
        ],
        'mail.booking.received_intro' => [
            'es' => 'Hemos recibido tu solicitud de reserva. Te avisaremos por email cuando quede confirmada. Detalles:',
            'en' => 'We have received your booking request. We will email you once it is confirmed. Details:',
            'ca' => 'Hem rebut la teva sol·licitud de reserva. T’avisarem per correu quan quedi confirmada. Detalls:',
            'gl' => 'Recibimos a túa solicitude de reserva. Avisarémoste por correo cando quede confirmada. Detalles:',
            'fr' => 'Nous avons bien reçu votre demande de réservation. Nous vous préviendrons par e-mail dès qu’elle sera confirmée. Détails :',
            'pt' => 'Recebemos o seu pedido de reserva. Avisaremos por e-mail assim que estiver confirmada. Detalhes:',
        ],
        'mail.booking.confirmed_now' => [
            'es' => 'Tu reserva ya está confirmada:', 'en' => 'Your booking is now confirmed:',
            'ca' => 'La teva reserva ja està confirmada:', 'gl' => 'A túa reserva xa está confirmada:',
            'fr' => 'Votre réservation est désormais confirmée :', 'pt' => 'A sua reserva já está confirmada:',
        ],
        'mail.booking.field_service' => [
            'es' => 'Servicio', 'en' => 'Service', 'ca' => 'Servei',
            'gl' => 'Servizo', 'fr' => 'Service', 'pt' => 'Serviço',
        ],
        'mail.booking.field_when' => [
            'es' => 'Fecha y hora', 'en' => 'Date and time', 'ca' => 'Data i hora',
            'gl' => 'Data e hora', 'fr' => 'Date et heure', 'pt' => 'Data e hora',
        ],
        'mail.booking.cancel_intro' => [
            'es' => 'Si necesitas cancelarla, puedes hacerlo aquí:',
            'en' => 'If you need to cancel it, you can do so here:',
            'ca' => 'Si necessites cancel·lar-la, pots fer-ho aquí:',
            'gl' => 'Se precisas cancelala, podes facelo aquí:',
            'fr' => 'Si vous devez l’annuler, vous pouvez le faire ici :',
            'pt' => 'Se precisar de a cancelar, pode fazê-lo aqui:',
        ],
        'mail.booking.cancel_inline' => [
            'es' => 'Si necesitas cancelarla: {url}', 'en' => 'If you need to cancel it: {url}',
            'ca' => 'Si necessites cancel·lar-la: {url}', 'gl' => 'Se precisas cancelala: {url}',
            'fr' => 'Si vous devez l’annuler : {url}', 'pt' => 'Se precisar de a cancelar: {url}',
        ],
        'mail.booking.cancelled_subject' => [
            'es' => 'Reserva cancelada: {service} — {when}',
            'en' => 'Booking cancelled: {service} — {when}',
            'ca' => 'Reserva cancel·lada: {service} — {when}',
            'gl' => 'Reserva cancelada: {service} — {when}',
            'fr' => 'Réservation annulée : {service} — {when}',
            'pt' => 'Reserva cancelada: {service} — {when}',
        ],
        'mail.booking.cancelled_body' => [
            'es' => 'Tu reserva del {when} ({service}) ha sido cancelada.',
            'en' => 'Your booking on {when} ({service}) has been cancelled.',
            'ca' => 'La teva reserva del {when} ({service}) s’ha cancel·lat.',
            'gl' => 'A túa reserva do {when} ({service}) foi cancelada.',
            'fr' => 'Votre réservation du {when} ({service}) a été annulée.',
            'pt' => 'A sua reserva de {when} ({service}) foi cancelada.',
        ],
        'mail.booking.book_again' => [
            'es' => 'Si quieres buscar otro hueco, puedes reservar de nuevo en nuestra web.',
            'en' => 'If you would like another slot, you can book again on our website.',
            'ca' => 'Si vols buscar una altra hora, pots reservar de nou al nostre web.',
            'gl' => 'Se queres buscar outro oco, podes reservar de novo na nosa web.',
            'fr' => 'Si vous souhaitez un autre créneau, vous pouvez réserver à nouveau sur notre site.',
            'pt' => 'Se quiser outro horário, pode reservar de novo no nosso site.',
        ],
        'mail.booking.ics_description' => [
            'es' => 'Reserva a nombre de {name}', 'en' => 'Booking for {name}',
            'ca' => 'Reserva a nom de {name}', 'gl' => 'Reserva a nome de {name}',
            'fr' => 'Réservation au nom de {name}', 'pt' => 'Reserva em nome de {name}',
        ],

        'booking.cancelled_again' => [
            'es' => 'Si quieres buscar otro hueco, puedes reservar de nuevo cuando quieras.',
            'en' => 'If you want another slot, you can book again whenever you like.',
            'ca' => 'Si vols buscar una altra hora, pots reservar de nou quan vulguis.',
            'gl' => 'Se queres buscar outro oco, podes reservar de novo cando queiras.',
            'fr' => 'Si vous cherchez un autre créneau, vous pouvez réserver à nouveau quand vous voulez.',
            'pt' => 'Se quiser outro horário, pode reservar de novo quando quiser.',
        ],
    ];

    /**
     * Texto en un idioma concreto, con interpolación opcional de datos.
     *
     * Los valores NO se escapan: eso es responsabilidad del punto de render,
     * que ya pasa el resultado por `e()`. Escapar aquí produciría doble
     * escapado en los nombres de producto con `&`, comillas o acentos.
     */
    public static function t(string $key, string $lang, array $vars = []): string
    {
        $entry = self::STRINGS[$key] ?? null;
        if ($entry === null) {
            return '';
        }
        $lang = LanguageService::normalize($lang);
        $text = $entry[$lang] ?? $entry[LanguageService::DEFAULT];

        return $vars === [] ? self::stripPlaceholders($text) : self::interpolate($text, $vars);
    }

    /** Texto en el idioma configurado del sitio. */
    public static function site(int $siteId, string $key, array $vars = []): string
    {
        return self::t($key, LanguageService::codeFor($siteId), $vars);
    }

    /**
     * Plantilla CRUDA, con los `{token}` intactos.
     *
     * Para clientes que interpolan ellos mismos —el widget de reservas, que es
     * JS y recibe los textos por la API—. Usar `t()` aquí sería un bug
     * silencioso: devuelve el texto ya limpio de placeholders, así que
     * «{n} créneaux» llegaría al navegador como «créneaux».
     */
    public static function template(string $key, string $lang): string
    {
        $entry = self::STRINGS[$key] ?? null;
        if ($entry === null) {
            return '';
        }
        return $entry[LanguageService::normalize($lang)] ?? $entry[LanguageService::DEFAULT];
    }

    /** Sustituye `{token}` por su valor. */
    private static function interpolate(string $text, array $vars): string
    {
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return self::stripPlaceholders($text);
    }

    /**
     * Elimina los `{token}` que se hayan quedado sin valor. Un hueco es feo;
     * un `{product}` crudo en un carrito parece una web rota.
     */
    private static function stripPlaceholders(string $text): string
    {
        if (!str_contains($text, '{')) {
            return $text;
        }
        $clean = preg_replace('/\{[a-z_][a-z0-9_]*\}/i', '', $text) ?? $text;
        return trim(preg_replace('/\s{2,}/', ' ', $clean) ?? $clean);
    }

    /**
     * Resuelve un texto respetando lo que haya escrito el usuario.
     *
     * `$stored` gana salvo que esté vacío o sea EXACTAMENTE el default
     * castellano histórico: en instalaciones anteriores a esto, ese valor no
     * lo eligió nadie, se guardó solo, y traducirlo es lo correcto.
     */
    public static function resolve(?string $stored, string $key, string $lang): string
    {
        $stored = trim((string) $stored);
        if ($stored !== '' && $stored !== self::t($key, LanguageService::DEFAULT)) {
            return $stored;
        }
        return self::t($key, $lang);
    }

    /** ¿La clave pertenece a un módulo opcional (tienda, reservas)? */
    public static function isModuleKey(string $key): bool
    {
        foreach (self::MODULE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Claves sin traducción para un idioma dado.
     *
     * Las claves de módulo solo se reclaman en `MODULE_LANGUAGES`: en el resto
     * de idiomas su ausencia es la política, no un hueco.
     *
     * @return array<int,string>
     */
    public static function missing(string $lang): array
    {
        $lang = LanguageService::normalize($lang);
        $moduleExpected = in_array($lang, self::MODULE_LANGUAGES, true);
        $out = [];
        foreach (self::STRINGS as $key => $entry) {
            if (self::isModuleKey($key) && !$moduleExpected) {
                continue;
            }
            if (trim($entry[$lang] ?? '') === '') {
                $out[] = $key;
            }
        }
        return $out;
    }

    /** @return array<int,string> Todas las claves del diccionario. */
    public static function keys(): array
    {
        return array_keys(self::STRINGS);
    }
}

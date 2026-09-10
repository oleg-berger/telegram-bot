<?php
declare(strict_types=1);

namespace Broadcast\Application;

/** Registration never requires a translation API call. */
final class Messages
{
    public const LANGUAGES = ['RU' => 'Русский', 'EN-GB' => 'English', 'ES' => 'Español', 'FR' => 'Français'];

    private const TEXT = [
        'RU' => [
            'language' => 'Выберите язык / Choose your language / Elige tu idioma / Choisissez votre langue',
            'name' => 'Как вас зовут?', 'company' => 'Укажите компанию.', 'country' => 'Укажите страну.',
            'phone' => 'Поделитесь своим контактом или введите телефон в международном формате, например +447700900123.',
            'contact' => 'Поделиться телефоном', 'invalid' => 'Проверьте ввод и попробуйте ещё раз.',
            'welcome' => 'Регистрация завершена. Вы подписаны на важные объявления.',
            'active' => 'Вы подписаны на рассылку.', 'stopped' => 'Подписка отключена. /start — подписаться снова.',
            'changed' => 'Язык сохранён. Он будет использоваться для следующих сообщений.',
            'help' => '/start — регистрация или подписка\n/language — смена языка\n/stop — отписка\n/help — помощь\nБот отправляет объявления. Ответы на сообщения не обрабатываются.',
        ],
        'EN-GB' => [
            'language' => 'Choose your language', 'name' => 'What is your name?', 'company' => 'Enter your company.',
            'country' => 'Enter your country.', 'phone' => 'Share your own contact or enter your international phone number, e.g. +447700900123.',
            'contact' => 'Share phone number', 'invalid' => 'Please check your input and try again.',
            'welcome' => 'Registration complete. You are subscribed to important announcements.',
            'active' => 'You are subscribed.', 'stopped' => 'You are unsubscribed. Use /start to subscribe again.',
            'changed' => 'Language saved. It will apply to future messages.',
            'help' => '/start — register or subscribe\n/language — change language\n/stop — unsubscribe\n/help — help\nThis bot sends announcements. Replies are not monitored.',
        ],
        'ES' => [
            'language' => 'Elige tu idioma', 'name' => '¿Cómo te llamas?', 'company' => 'Indica tu empresa.',
            'country' => 'Indica tu país.', 'phone' => 'Comparte tu propio contacto o introduce tu teléfono en formato internacional, por ejemplo +447700900123.',
            'contact' => 'Compartir teléfono', 'invalid' => 'Comprueba los datos e inténtalo de nuevo.',
            'welcome' => 'Registro completado. Te has suscrito a los anuncios importantes.',
            'active' => 'Estás suscrito.', 'stopped' => 'Suscripción cancelada. Usa /start para volver a suscribirte.',
            'changed' => 'Idioma guardado. Se aplicará a los próximos mensajes.',
            'help' => '/start — registrarse o suscribirse\n/language — cambiar idioma\n/stop — cancelar suscripción\n/help — ayuda\nEste bot envía anuncios. No se atienden las respuestas.',
        ],
        'FR' => [
            'language' => 'Choisissez votre langue', 'name' => 'Comment vous appelez-vous ?', 'company' => 'Indiquez votre entreprise.',
            'country' => 'Indiquez votre pays.', 'phone' => 'Partagez votre propre contact ou saisissez votre numéro au format international, par exemple +447700900123.',
            'contact' => 'Partager mon numéro', 'invalid' => 'Vérifiez votre saisie et réessayez.',
            'welcome' => 'Inscription terminée. Vous êtes abonné aux annonces importantes.',
            'active' => 'Vous êtes abonné.', 'stopped' => 'Vous êtes désabonné. Utilisez /start pour vous réabonner.',
            'changed' => 'Langue enregistrée. Elle sera utilisée pour les prochains messages.',
            'help' => '/start — inscription ou abonnement\n/language — changer de langue\n/stop — se désabonner\n/help — aide\nCe bot diffuse des annonces. Les réponses ne sont pas traitées.',
        ],
    ];

    public static function text(string $language, string $key): string
    {
        return str_replace('\\n', "\n", self::TEXT[$language][$key]);
    }

    public static function languageKeyboard(): array
    {
        $buttons = [];
        foreach (self::LANGUAGES as $code => $label) { $buttons[] = ['text' => $label, 'callback_data' => 'language:' . $code]; }
        return ['reply_markup' => ['inline_keyboard' => array_chunk($buttons, 2)]];
    }
}

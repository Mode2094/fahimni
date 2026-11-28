<?php

namespace App\Support;

/**
 * هل يمكن للبريد المعامَلاتي (تفعيل الحساب، إعادة التعيين، الدعوات، التنبيهات)
 * أن يصل فعلاً إلى صندوق بريد خارجي؟
 *
 * القرار مشتق من الإعدادات فقط (MAIL_MAILER / MAIL_HOST) — لا من وجود حساب
 * بعينه — لذلك يمكن كشفه للعميل ليكون صادقاً في واجهة «نسيت كلمة المرور»
 * دون أن يسمح بمعرفة ما إذا كان البريد مسجّلاً أم لا (منع تعداد الحسابات).
 */
class MailDelivery
{
    /** اسم مضيفات البريد المحلية/التجريبية التي لا تسلّم لبريد خارجي. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', 'mailhog', 'mailpit', 'host.docker.internal'];

    /**
     * هل الإعداد الحالي يسلّم بريداً حقيقياً؟ `false` تعني أن الرسل سيُكتب في
     * السجل (log) أو يُبتلع (array) أو يذهب لمضيف تجريبي — أي لن يصل للمستخدم.
     */
    public static function isConfigured(): bool
    {
        return self::mailerDelivers(strtolower(trim((string) config('mail.default', ''))), 0);
    }

    private static function mailerDelivers(string $name, int $depth): bool
    {
        if ($name === '' || $depth > 3) {
            return false;
        }

        // رسل لا تسلّم أبداً إلى صندوق بريد حقيقي.
        if (in_array($name, ['log', 'array', 'null'], true)) {
            return false;
        }

        $config = config("mail.mailers.{$name}");

        if (! is_array($config)) {
            // اسم رسل غير معروف في config/mail.php.
            return false;
        }

        $transport = strtolower((string) ($config['transport'] ?? $name));

        if (in_array($transport, ['log', 'array', 'null'], true)) {
            return false;
        }

        // failover/roundrobin: يكفي أن يكون أحد الرسل الفرعية مُهيّأً فعلاً.
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ((array) ($config['mailers'] ?? []) as $sub) {
                if (self::mailerDelivers(strtolower((string) $sub), $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if ($transport === 'smtp' || $name === 'smtp') {
            return self::hostIsReal((string) ($config['host'] ?? ''));
        }

        // sendmail / ses / postmark / mailgun / resend: تُسلّم بمجرد تهيئتها.
        return true;
    }

    private static function hostIsReal(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        // قيم القوالب الجاهزة (تُبقي الإعداد "غير مُهيّأ" حتى يُستبدل بمزوّد حقيقي).
        foreach (['example.com', 'example.org', 'example.net', 'your-provider', 'changeme', 'change_me'] as $placeholder) {
            if (str_contains($host, $placeholder)) {
                return false;
            }
        }

        // localhost مضيف صالح محلياً لكنه لا يسلّم بريد المستخدمين في الإنتاج.
        if (in_array($host, self::LOCAL_HOSTS, true) && app()->environment('production')) {
            return false;
        }

        return true;
    }
}

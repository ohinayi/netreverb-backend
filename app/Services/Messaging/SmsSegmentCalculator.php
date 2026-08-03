<?php

namespace App\Services\Messaging;

class SmsSegmentCalculator
{
    public function units(string $message): int
    {
        if ($message === '') {
            return 0;
        }

        $isGsmCompatible = preg_match(
            "/^[\r\n !\"#\\$%&'()*+,\\-.\\/0-9:;<=>?@A-Z\\\\_a-z¡-¿£¥èéùìòÇØøÅåΔΦΓΛΩΠΨΣΘΞÆæßÉÄÖÑÜ§äöñüà^{}\\[~\\]|€]*$/u",
            $message,
        ) === 1;

        if (! $isGsmCompatible) {
            $length = mb_strlen($message);

            return $length <= 70 ? 1 : (int) ceil($length / 67);
        }

        $length = mb_strlen($message);
        $length += preg_match_all('/[\\^{}\\[\\]~|€]/u', $message);

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }
}

<?php

namespace App\Support;

/** Quanto de um lançamento é hora adicional e quanto vale já com os fatores. */
final class AdditionalHoursResult
{
    public const WEEKDAY = 'weekday';

    public const SATURDAY = 'saturday';

    public const SUNDAY = 'sunday';

    public const HOLIDAY = 'holiday';

    public function __construct(
        /** Segundos do lançamento fora do expediente (ou o dia todo, em sábado/domingo/feriado). */
        public readonly int $additionalSeconds,
        /** Parte dos segundos adicionais que cai na janela noturna. */
        public readonly int $nightSeconds,
        /** Segundos já multiplicados pelo fator do tipo de dia (e pelo adicional noturno). */
        public readonly int $weightedSeconds,
        public readonly string $dayType,
    ) {}

    public static function none(string $dayType = self::WEEKDAY): self
    {
        return new self(0, 0, 0, $dayType);
    }

    public function isAdditional(): bool
    {
        return $this->additionalSeconds > 0;
    }
}

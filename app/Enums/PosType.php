<?php

namespace App\Enums;

enum PosType: string
{
    case Jowi = 'jowi';
    case Poster = 'poster';
    case Iiko = 'iiko';
    case EscPos = 'escpos';
    case Manual = 'manual';
    case Browser = 'browser';

    /** POS API orqali ishlaydimi (chekni POS o'zi chiqaradi). */
    public function usesExternalApi(): bool
    {
        return in_array($this, [self::Jowi, self::Poster, self::Iiko], true);
    }

    /** Chek print agent orqali chiqadimi. */
    public function usesPrintAgent(): bool
    {
        return $this === self::EscPos;
    }

    /** Chek oshxona panelidan (brauzer) Windows printeriga chiqadimi — agentsiz, `/kitchen` sahifasi orqali. */
    public function usesBrowserPrint(): bool
    {
        return $this === self::Browser;
    }

    public function label(): string
    {
        return __("messages.pos_type.{$this->value}");
    }
}

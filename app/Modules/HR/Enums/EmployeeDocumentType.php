<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * EmployeeDocumentType — نوع الوثيقة
 *
 * What a file attached to an employee actually is. The first four are the
 * images the new-hire intake form collects on every hire; the rest are what the
 * HR folder already holds on paper.
 *
 * ⚠️ `ShamCash` IS SENSITIVE. A photograph of a payment account is the account
 * number, so a listing of documents must gate that type behind
 * hr.employees.viewSalary exactly as the `sham_cash_account` column is
 * (phase-1.5-foundation-truth-up.md §6.2). Whoever writes the document
 * endpoints owes that gate — the type existing here is not the gate.
 */
enum EmployeeDocumentType: int
{
    case IdFront               = 1;   // صورة الهوية - الوجه
    case IdBack                = 2;   // صورة الهوية - الظهر
    case GraduationCertificate = 3;   // الشهادة العلمية
    case ShamCash              = 4;   // صورة حساب الشام كاش
    case PersonalPhoto         = 5;   // الصورة الشخصية
    case Cv                    = 6;   // السيرة الذاتية
    case Contract              = 7;   // عقد العمل
    case Other                 = 8;   // أخرى
    case AppointmentDecision   = 9;   // قرار تعيين
    case PromotionDecision     = 10;  // قرار ترقية
    case WorkCertificate       = 11;  // شهادة عمل
    case Warning               = 12;  // إنذار

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::IdFront               => 'صورة الهوية - الوجه',
            self::IdBack                => 'صورة الهوية - الظهر',
            self::GraduationCertificate => 'الشهادة العلمية',
            self::ShamCash              => 'صورة حساب الشام كاش',
            self::PersonalPhoto         => 'الصورة الشخصية',
            self::Cv                    => 'السيرة الذاتية',
            self::Contract              => 'عقد العمل',
            self::Other                 => 'أخرى',
            self::AppointmentDecision   => 'قرار تعيين',
            self::PromotionDecision     => 'قرار ترقية',
            self::WorkCertificate       => 'شهادة عمل',
            self::Warning               => 'إنذار',
        };
    }

    /**
     * Whether this kind of document is a payment instrument, and so reads
     * through the same gate as the salary fields (C11).
     */
    public function isPaymentInstrument(): bool
    {
        return $this === self::ShamCash;
    }

    /**
     * Whether this kind of document reads only for a caller whose
     * hr.employees.viewSalary grant reaches the employee: a payment account,
     * and the papers that state pay (contract, appointment, promotion).
     */
    public function readsThroughSalary(): bool
    {
        return match ($this) {
            self::ShamCash, self::Contract, self::AppointmentDecision, self::PromotionDecision => true,
            default => false,
        };
    }

    /**
     * The types a document sent to an employee may be filed as.
     *
     * @return array<int, self>
     */
    public static function forSentDocuments(): array
    {
        return [
            self::Contract,
            self::Other,
            self::AppointmentDecision,
            self::PromotionDecision,
            self::WorkCertificate,
            self::Warning,
        ];
    }

    public static function isDefined(int $value): bool
    {
        return self::tryFrom($value) instanceof self;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Exceptions\DocumentRouteException;
use App\Modules\Documents\Exceptions\NotAUnitManagerException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\RecipientValues;
use App\Modules\Documents\Support\SendDocumentInput;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sending a document to one employee (documents design §1.4, workflow 1;
 * §9): one of HR's managers sends an upload or a `for_employees` template,
 * and the document waits for the recipient's signature. It never touches the
 * approvals engine: no approval, no route, no approver.
 *
 * Everything that can be refused is refused first, before a byte is written:
 * the sender, the recipient, the template with its values, the صادر number.
 * An upload is then stored (ruling R1), and one transaction makes the row. A
 * template's numbers and page belong to that transaction instead: it re-reads
 * the template, and `DocumentOriginals` checks the values again (ruling R4),
 * claims the صادر number in the sender's إدارة's register and the reference,
 * and prints the page with the recipient's chips. The recipient is told in
 * the same transaction, as piece 2 tells: the inbox row commits with the
 * document, and only the push waits for the commit. A throw — the row, the
 * print, the inbox row — removes the folder and rolls the numbers back.
 *
 * WHO: the sender is the caller's own employee, the manager of HR or of a
 * unit under it (`HrSenders`).
 */
final class DocumentSending
{
    private const NOT_A_SENDER = 'لا يرسل المستندات إلى الموظفين إلا مديرو الموارد البشرية.';

    public function __construct(
        private readonly HrSenders $senders,
        private readonly UnitManagers $managers,
        private readonly DocumentOriginals $originals,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws NotAUnitManagerException the sender is not one of HR's managers (403)
     * @throws DocumentRouteException
     * @throws ValidationException a template's values, under `field_values.{key}`
     */
    public function send(Employee $sender, User $by, SendDocumentInput $input): Document
    {
        $unit = $this->senders->senderUnit($sender) ?? throw new NotAUnitManagerException(self::NOT_A_SENDER);

        [$recipient, $account] = $this->recipient($sender, $input->recipientId);

        $register = $this->managers->registerOf($unit);

        if ($input->templateId !== null) {
            $this->originals->checkTemplate(
                $this->originals->template($input->templateId, forEmployees: true),
                $input->fieldValues,
                $register,
                $input->exportNumber,
            );
        }

        $folder = (string) Str::uuid();
        $directory = "documents/{$folder}";

        $row = [
            'kind'             => DocumentKind::ToEmployee,
            'title'            => $input->title,
            'description'      => $input->description,
            'creator_id'       => $sender->getKey(),
            'creator_unit_id'  => $unit->getKey(),
            'register_unit_id' => $register->getKey(),
            'created_by'       => $by->getKey(),
            'recipient_id'     => $recipient->getKey(),
            'filing_type'      => $input->filingType,
            'status'           => DocumentStatus::AwaitingSignature,
            'folder'           => $folder,
        ];

        if ($input->templateId === null) {
            // Before the transaction (ruling R1); it removes the folder if it throws.
            $row += $this->originals->storeUpload($input->file, $directory)->columns();
        }

        return DB::transaction(function () use ($input, $sender, $unit, $register, $recipient, $account, $directory, $row): Document {
            // Inside the transaction, not around it, as piece 2's creation: a
            // throw from an after-commit callback must not delete the files the
            // committed document names.
            try {
                if ($input->templateId !== null) {
                    // The template re-read — the version recorded and the page
                    // printed are this read's — then its values checked again
                    // (ruling R4), the numbers of the sender's إدارة claimed and
                    // its page printed with the recipient's chips.
                    $row += $this->originals->printTemplate(
                        $this->originals->template($input->templateId, forEmployees: true),
                        $input->fieldValues,
                        $register,
                        $input->exportNumber,
                        SystemValues::unnumbered((string) $sender->full_name, (string) $unit->name),
                        $this->recipientValues($recipient),
                        $directory,
                    )->columns();
                }

                $document = $this->originals->createDocument($row + ['sent_at' => now()]);

                $this->tellRecipient($account, $sender, $document);

                return $document;
            } catch (Throwable $e) {
                $this->originals->discard($directory);

                throw $e;
            }
        });
    }

    /**
     * The recipient, in this order: an employee (not deleted), on duty, with
     * an active account, and not the sender.
     *
     * @return array{0: Employee, 1: User} the employee and their account
     *
     * @throws DocumentRouteException
     */
    private function recipient(Employee $sender, int $id): array
    {
        $recipient = Employee::query()->find($id)
            ?? throw new DocumentRouteException('الموظف المحدد غير موجود.');

        if ($recipient->status !== EmployeeStatus::Active) {
            throw new DocumentRouteException("لا يمكن إرسال مستند إلى «{$recipient->full_name}» لأنه ليس على رأس عمله.");
        }

        $account = User::query()->where('employee_id', $recipient->getKey())->first();

        if ($account === null || ! $account->isActive()) {
            throw new DocumentRouteException("لا يمكن إرسال مستند إلى «{$recipient->full_name}» لأنه لا يملك حساباً فعّالاً.");
        }

        if ($recipient->is($sender)) {
            throw new DocumentRouteException('لا يمكنك إرسال مستند إلى نفسك.');
        }

        return [$recipient, $account];
    }

    /**
     * The recipient's `recipient.*` chips (Review Focus 4): a job title or a
     * unit they do not have prints empty, and the document still goes.
     */
    private function recipientValues(Employee $recipient): RecipientValues
    {
        return new RecipientValues(
            name: (string) $recipient->full_name,
            jobTitle: (string) ($recipient->jobTitle?->name ?? ''),
            unit: (string) ($recipient->department?->name ?? ''),
            employeeNumber: (string) ($recipient->employee_number ?? ''),
        );
    }

    /**
     * Inside the sending's transaction: the inbox row commits with the
     * document, and only the push waits for the commit (`NotificationService`),
     * so a rolled-back sending tells no one.
     */
    private function tellRecipient(User $account, Employee $sender, Document $document): void
    {
        $this->notifications->sendToUser(
            $account,
            'مستند جديد بانتظار توقيعك',
            "أرسل إليك {$sender->full_name} المستند «{$document->title}».",
            type: NotificationEventCatalogue::DOCUMENT_AWAITING_SIGNATURE,
            reference: $document,
        );
    }
}

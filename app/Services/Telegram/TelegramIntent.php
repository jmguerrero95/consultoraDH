<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * What a Telegram sentence asks the bot to do.
 *
 * The set is deliberately small and closed. A phrase either names a business
 * read the bot can perform deterministically, a write it can propose, or
 * nothing the bot recognises. "Nothing recognised" is the only case that may
 * reach a language model; a recognised business query never does, so an
 * unavailable provider cannot take `/tareas` down with it.
 */
enum TelegramIntent: string
{
    case Tasks = 'tasks';
    case Reminders = 'reminders';
    case CreateTask = 'create_task';
    case CreateReminder = 'create_reminder';
    case Clients = 'clients';
    case ClientsByCompany = 'clients_by_company';
    case ClientAccount = 'client_account';
    case Receivables = 'receivables';
    case Sheets = 'sheets';
    case Documents = 'documents';
    case SendDocument = 'send_document';
    case Help = 'help';
    case Unknown = 'unknown';

    public function isWrite(): bool
    {
        return $this === self::CreateTask || $this === self::CreateReminder;
    }

    public function isBusinessRead(): bool
    {
        return ! $this->isWrite() && $this !== self::Unknown && $this !== self::Help;
    }
}

<?php
/**
 * relationships/api/outgrow-ticket-parse.php
 *
 * Pure helpers (no DB, no session, no output) for the "Ticket -> OutGrow"
 * feature added 2026-10-09 per Michael: a rep pastes the text of a
 * ConnectWise "SR#### - OutGrow Action - From <name>" service ticket and the
 * app builds the matching pre-filled OutGrow (Formstack) call-activity form.
 *
 * Kept separate from outgrow-ticket.php so the parser can be unit-tested
 * from the command line (see relationships/api/tests/outgrow-ticket-test.php).
 *
 * The form still has to be SUBMITTED by the rep -- it carries an invisible
 * reCAPTCHA and CBT has no Formstack API key (full reasoning in
 * meetings.php's relationships_formstack_todo_url() docblock). This only
 * does the typing.
 *
 * Mapping, per Michael's worked example (SR#953911):
 *   Your Name / Your Email  -> the CBT person who WROTE the action note on
 *                              the ticket (not whoever pastes it). Email is
 *                              first initial + last name @codebluetechnology.com
 *   Client/Prospect Type    -> "Current Customer"
 *   Client/Prospect Company -> ticket "Company:" line, verbatim
 *   Contact                 -> ticket "Contact:" line, verbatim
 *   Actions / F/U Plan      -> that note's header line + body, verbatim
 *   Proactive Call          -> "0"
 *   Call Type               -> "NOT A Call"
 *   DYK                     -> "1"
 *   Pivot to Sale / Next    -> "1"
 *
 * Field ids (field190744403 ...) are Formstack's per-field numeric ids; the
 * first nine were read off the live form on 2026-09-17 (see meetings.php).
 *
 * Confirmed against the live form 2026-10-09 (read via Claude in Chrome): DYK is
 * field190744413 (options 0-4); the Call Type option is spelled "NOT A Call".
 * Formstack only pre-selects a dropdown when the text matches exactly.
 */

declare(strict_types=1);

const OUTGROW_FORM_URL = 'https://outgrow.formstack.com/forms/oa_brittany_toler_code_blue';
const OUTGROW_EMAIL_DOMAIN = 'codebluetechnology.com';

const OUTGROW_FIELD_EMAIL = 'field190744403';
const OUTGROW_FIELD_NAME = 'field190744404';
const OUTGROW_FIELD_TYPE = 'field190744405';
const OUTGROW_FIELD_COMPANY = 'field190744406';
const OUTGROW_FIELD_CONTACT = 'field190744407';
const OUTGROW_FIELD_ACTIONS = 'field190744408';
const OUTGROW_FIELD_PROACTIVE = 'field190744411';
const OUTGROW_FIELD_CALL_TYPE = 'field190744412';
const OUTGROW_FIELD_PIVOT = 'field190744415';
const OUTGROW_FIELD_DYK = 'field190744413'; // confirmed off the live form 2026-10-09

const OUTGROW_CALL_TYPE_NOT_A_CALL = 'NOT A Call'; // exact option text, confirmed 2026-10-09

/**
 * "Fri 10/9/2026/8:10 AM EDT/ Chuck Fleet (time)-" -> author "Chuck Fleet",
 * or null when $line isn't a discussion-entry header.
 */
function relationships_outgrow_header_author(string $line): ?string
{
    if (!preg_match(
        '~^\s*(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*\.?\s+\d{1,2}/\d{1,2}/\d{4}\s*/\s*\d{1,2}:\d{2}\s*[AP]M\s*[A-Za-z]{2,5}\s*/\s*(.+?)\s*$~i',
        $line,
        $m
    )) {
        return null;
    }
    $author = preg_replace('/\s*\([^)]*\)\s*-?\s*$/', '', $m[1]) ?? $m[1]; // "(time)-" etc.
    $author = trim($author, " \t-");
    return $author !== '' ? $author : null;
}

/**
 * "Fri 10/9/2026/8:10 AM EDT/ Chuck Fleet (time)-" -> "2026-10-09" (the
 * note's own calendar date, exactly as written -- no timezone conversion),
 * or null when the line has no valid date.
 */
function relationships_outgrow_header_date(string $line): ?string
{
    if (!preg_match('~^\s*(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*\.?\s+(\d{1,2})/(\d{1,2})/(\d{4})\s*/~i', $line, $m)) {
        return null;
    }
    [$month, $day, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    if (!checkdate($month, $day, $year)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** Value after "Label:" on the same line, else the next non-empty line. */
function relationships_outgrow_label_value(array $lines, string $label): string
{
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        if (preg_match('/^\s*' . preg_quote($label, '/') . '\s*:\s*(.*)$/i', $lines[$i], $m)) {
            $value = trim($m[1]);
            if ($value !== '') {
                return $value;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $next = trim($lines[$j]);
                if ($next !== '') {
                    return preg_match('/^[A-Za-z ]{2,20}:\s*$/', $next) ? '' : $next;
                }
            }
            return '';
        }
        if (preg_match('/^\s*Discussion\s*:?\s*$/i', $lines[$i])) {
            break; // never read header fields out of the discussion body
        }
    }
    return '';
}

/** "Chuck Fleet" -> "cfleet@codebluetechnology.com"; null if it can't be built. */
function relationships_outgrow_email_for(string $name): ?string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (count($parts) < 2) {
        return null;
    }
    $first = strtolower((string) preg_replace('/[^A-Za-z]/', '', $parts[0]));
    $last = strtolower((string) preg_replace('/[^A-Za-z]/', '', $parts[count($parts) - 1]));
    if ($first === '' || $last === '') {
        return null;
    }
    return $first[0] . $last . '@' . OUTGROW_EMAIL_DOMAIN;
}

/**
 * Parses pasted ticket text.
 * Returns ['ok' => true, 'values' => [...], 'warnings' => [...]] or
 * ['ok' => false, 'error' => '...'].
 */
function relationships_outgrow_parse_ticket(string $text): array
{
    $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
    $lines = explode("\n", $text);

    $company = relationships_outgrow_label_value($lines, 'Company');
    $contact = relationships_outgrow_label_value($lines, 'Contact');
    $ticketNo = '';
    if (preg_match('/Ticket\s*#?\s*(\d{4,})/i', $text, $tm)) {
        $ticketNo = $tm[1];
    }

    // Split every line after "Discussion:" (or the whole text, if that label
    // is missing) into entries at each timestamp header.
    $start = 0;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*Discussion\s*:?\s*$/i', $line)) {
            $start = $i + 1;
            break;
        }
    }
    $entries = [];
    $current = null;
    for ($i = $start, $n = count($lines); $i < $n; $i++) {
        $author = relationships_outgrow_header_author($lines[$i]);
        if ($author !== null) {
            if ($current !== null) {
                $entries[] = $current;
            }
            $current = ['header' => trim($lines[$i]), 'author' => $author, 'body' => []];
        } elseif ($current !== null) {
            $current['body'][] = $lines[$i];
        }
    }
    if ($current !== null) {
        $entries[] = $current;
    }

    if ($entries === []) {
        return ['ok' => false, 'error' => 'Couldn’t find a dated discussion entry (e.g. "Fri 10/9/2026/8:10 AM EDT/ Chuck Fleet") in that text. Paste the whole ticket, including the Discussion section.'];
    }

    // The OutGrow action is the newest note written by one of OUR people --
    // skip entries the customer contact wrote themselves.
    $chosen = null;
    foreach ($entries as $e) {
        if ($contact === '' || strcasecmp($e['author'], $contact) !== 0) {
            $chosen = $e;
            break;
        }
    }
    $warnings = [];
    if ($chosen === null) {
        $chosen = $entries[0];
        $warnings[] = 'Every note on this ticket was written by the contact, so the newest one was used.';
    }

    $body = trim(implode("\n", $chosen['body']));
    $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;
    $actions = $chosen['header'] . ($body !== '' ? "\n" . $body : '');

    $email = relationships_outgrow_email_for($chosen['author']);
    if ($email === null) {
        $warnings[] = 'Couldn’t build an email from "' . $chosen['author'] . '" (needs a first and last name) — fill in Your Email on the form.';
    }
    if ($company === '') {
        $warnings[] = 'No "Company:" line found — fill in the company on the form.';
    }
    if ($contact === '') {
        $warnings[] = 'No "Contact:" line found — fill in the contact on the form.';
    }
    if (OUTGROW_FIELD_DYK === null) {
        $warnings[] = 'DYK is not pre-filled yet (its form field id isn’t configured) — set DYK to 1 on the form.';
    }

    return [
        'ok' => true,
        'values' => [
            'ticket' => $ticketNo,
            'note_date' => relationships_outgrow_header_date($chosen['header']),
            'email' => $email ?? '',
            'name' => $chosen['author'],
            'type' => 'Current Customer',
            'company' => $company,
            'contact' => $contact,
            'actions' => $actions,
            'proactive_call' => '0',
            'call_type' => OUTGROW_CALL_TYPE_NOT_A_CALL,
            'dyk' => '1',
            'pivot' => '1',
        ],
        'warnings' => $warnings,
    ];
}

/** Pre-filled Formstack URL for a parsed ticket's 'values'. */
function relationships_outgrow_form_url(array $v): string
{
    $fields = [
        OUTGROW_FIELD_EMAIL => $v['email'],
        OUTGROW_FIELD_NAME => $v['name'],
        OUTGROW_FIELD_TYPE => $v['type'],
        OUTGROW_FIELD_COMPANY => $v['company'],
        OUTGROW_FIELD_CONTACT => $v['contact'],
        OUTGROW_FIELD_ACTIONS => $v['actions'],
        OUTGROW_FIELD_PROACTIVE => $v['proactive_call'],
        OUTGROW_FIELD_CALL_TYPE => $v['call_type'],
        OUTGROW_FIELD_PIVOT => $v['pivot'],
    ];
    if (OUTGROW_FIELD_DYK !== null) {
        $fields[OUTGROW_FIELD_DYK] = $v['dyk'];
    }
    return OUTGROW_FORM_URL . '?' . http_build_query($fields);
}

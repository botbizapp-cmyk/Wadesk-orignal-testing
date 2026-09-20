<?php

namespace App\Services\AiCrm;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Support\Facades\Log;

/**
 * The CRM tool registry for the AI Copilot.
 *
 * Each tool is a provider-neutral definition (name + description + JSON-schema
 * params) plus a handler that reuses the SAME models/logic the human UI uses,
 * always scoped to one workspace. Read tools run autonomously; write tools are
 * flagged `write` so AiCrmCopilotService can enforce confirm-before-act.
 *
 * Provider adapters (OpenAI / Anthropic / Gemini) are built from definitions()
 * inside AiCrmCopilotService — this class never talks to an LLM.
 */
class CrmToolkit
{
    /** @var array{workspace_id:int, user_id:?int} */
    private array $ctx;

    public function __construct(int $workspaceId, ?int $userId)
    {
        $this->ctx = ['workspace_id' => $workspaceId, 'user_id' => $userId];
    }

    /** Provider-neutral tool schemas. Order = rough priority for the model. */
    public function definitions(): array
    {
        return [
            [
                'name' => 'find_contact',
                'kind' => 'read',
                'description' => 'Search CRM contacts by name or phone number. Returns matching contacts with their open-deal count.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'A name fragment or phone number to search for.'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'create_contact',
                'kind' => 'write',
                'description' => 'Create a new CRM contact (or return the existing one if the phone already exists).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Full name.'],
                        'phone' => ['type' => 'string', 'description' => 'Phone number in international format, digits only preferred.'],
                        'country_code' => ['type' => 'string', 'description' => 'Optional country dial code e.g. +91.'],
                        'email' => ['type' => 'string', 'description' => 'Optional email address.'],
                    ],
                    'required' => ['name', 'phone'],
                ],
            ],
            [
                'name' => 'list_deals',
                'kind' => 'read',
                'description' => 'List deals in the sales pipeline, optionally filtered by status (open/won/lost) or stage name.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['open', 'won', 'lost', 'any'], 'description' => 'Filter by status. Default open.'],
                        'stage' => ['type' => 'string', 'description' => 'Optional stage name fragment.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max rows (default 15, max 50).'],
                    ],
                ],
            ],
            [
                'name' => 'create_deal',
                'kind' => 'write',
                'description' => 'Create a new deal on the sales pipeline. Optionally attach it to a contact by phone and set a value.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Deal name/title.'],
                        'value' => ['type' => 'number', 'description' => 'Deal value in the pipeline currency (major units, e.g. 15000).'],
                        'contact_phone' => ['type' => 'string', 'description' => 'Optional phone of the contact to link.'],
                        'stage' => ['type' => 'string', 'description' => 'Optional stage name; defaults to the first stage.'],
                    ],
                    'required' => ['title'],
                ],
            ],
            [
                'name' => 'move_deal_stage',
                'kind' => 'write',
                'description' => 'Move an existing deal to a different pipeline stage (e.g. to mark it won or lost).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'deal_id' => ['type' => 'integer', 'description' => 'The deal id to move.'],
                        'stage' => ['type' => 'string', 'description' => 'Target stage name fragment (e.g. "Won", "Proposal").'],
                    ],
                    'required' => ['deal_id', 'stage'],
                ],
            ],
            [
                'name' => 'find_company',
                'kind' => 'read',
                'description' => 'Search companies/organizations by name. Returns matches with their contact + open-deal counts.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'A company name fragment.'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'create_company',
                'kind' => 'write',
                'description' => 'Create a new company/organization.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name'     => ['type' => 'string', 'description' => 'Company name.'],
                        'industry' => ['type' => 'string', 'description' => 'Optional industry.'],
                        'website'  => ['type' => 'string', 'description' => 'Optional website.'],
                        'email'    => ['type' => 'string', 'description' => 'Optional email.'],
                        'phone'    => ['type' => 'string', 'description' => 'Optional phone.'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'link_contact_company',
                'kind' => 'write',
                'description' => 'Link an existing contact (by phone) to a company (by name). Creates the company if it does not exist.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'contact_phone' => ['type' => 'string', 'description' => 'Phone of the contact to link.'],
                        'company_name'  => ['type' => 'string', 'description' => 'Company name to link the contact to.'],
                    ],
                    'required' => ['contact_phone', 'company_name'],
                ],
            ],
            [
                'name' => 'sales_report',
                'kind' => 'read',
                'description' => 'Get a sales summary: open pipeline value, weighted forecast, deals won this month, and win rate.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'create_invoice',
                'kind' => 'write',
                'description' => 'Create (and issue) an invoice for a customer with one or more line items. Gets a real number + PDF.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'buyer_name'  => ['type' => 'string', 'description' => 'Customer / bill-to name.'],
                        'buyer_phone' => ['type' => 'string', 'description' => 'Optional customer phone.'],
                        'currency'    => ['type' => 'string', 'description' => 'ISO currency code, e.g. USD. Defaults to the workspace currency.'],
                        'items'       => [
                            'type' => 'array',
                            'description' => 'Line items.',
                            'items' => ['type' => 'object', 'properties' => [
                                'description' => ['type' => 'string'],
                                'qty'         => ['type' => 'number'],
                                'unit_price'  => ['type' => 'number', 'description' => 'Price per unit in major units.'],
                                'tax_rate'    => ['type' => 'number', 'description' => 'Optional tax percent.'],
                            ]],
                        ],
                    ],
                    'required' => ['buyer_name', 'items'],
                ],
            ],
            [
                'name' => 'record_payment',
                'kind' => 'write',
                'description' => 'Record a payment received (full or partial). Optionally against an invoice number so its balance clears.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'amount'         => ['type' => 'number', 'description' => 'Amount received in major units.'],
                        'currency'       => ['type' => 'string', 'description' => 'ISO currency code. Defaults to the invoice/workspace currency.'],
                        'method'         => ['type' => 'string', 'description' => 'manual|cash|bank|card|upi.'],
                        'invoice_number' => ['type' => 'string', 'description' => 'Optional invoice number to apply the payment to.'],
                        'contact_phone'  => ['type' => 'string', 'description' => 'Optional payer contact phone to attribute revenue.'],
                    ],
                    'required' => ['amount'],
                ],
            ],
            [
                'name' => 'list_outstanding',
                'kind' => 'read',
                'description' => 'List unpaid / partly-paid invoices and the total outstanding + aging.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'create_task',
                'kind' => 'write',
                'description' => 'Create a follow-up task / to-do with an optional due date, priority and assignee.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title'        => ['type' => 'string', 'description' => 'What needs doing.'],
                        'due_at'       => ['type' => 'string', 'description' => 'Optional due date/time (ISO or natural).'],
                        'priority'     => ['type' => 'string', 'description' => 'low|medium|high.'],
                        'assignee_name'=> ['type' => 'string', 'description' => 'Optional workspace member to assign to (by name).'],
                    ],
                    'required' => ['title'],
                ],
            ],
            [
                'name' => 'assign_task',
                'kind' => 'write',
                'description' => 'Assign an existing task (by id) to a workspace member (by name).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id'       => ['type' => 'integer', 'description' => 'Task id.'],
                        'assignee_name' => ['type' => 'string', 'description' => 'Member name to assign to.'],
                    ],
                    'required' => ['task_id', 'assignee_name'],
                ],
            ],
            [
                'name' => 'complete_task',
                'kind' => 'write',
                'description' => 'Mark a task (by id) as done.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['task_id' => ['type' => 'integer', 'description' => 'Task id.']],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'list_my_tasks',
                'kind' => 'read',
                'description' => 'List the open tasks for the current staff member (overdue first).',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'revenue_report',
                'kind' => 'read',
                'description' => 'Get a money summary: collected (30d + all-time), outstanding, tax collected, and paid/open invoice counts.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'generate_brief',
                'kind' => 'read',
                'description' => 'Generate a shareable client brief / deck (with a public link + PDF) for a company or contact by name.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'subject_type' => ['type' => 'string', 'description' => 'company|contact.'],
                        'name'         => ['type' => 'string', 'description' => 'The company or contact name.'],
                    ],
                    'required' => ['subject_type', 'name'],
                ],
            ],
            // ---- Phase 6 project tools ----
            [
                'name' => 'create_project',
                'kind' => 'write',
                'description' => 'Create a delivery project (post-sale work tracking). Optionally tie it to a company, an owner and a due date.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name'         => ['type' => 'string', 'description' => 'Project name.'],
                        'company_name' => ['type' => 'string', 'description' => 'Optional company to link.'],
                        'owner_name'   => ['type' => 'string', 'description' => 'Optional workspace member who owns it.'],
                        'due_date'     => ['type' => 'string', 'description' => 'Optional due date, YYYY-MM-DD.'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'list_projects',
                'kind' => 'read',
                'description' => 'List active projects with progress %, and how many are overdue or completed.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            // ---- Phase 7 quote tools (proposals + estimates) ----
            [
                'name' => 'create_quote',
                'kind' => 'write',
                'description' => 'Create a proposal or estimate (priced quote) with line items. Returns a shareable public link. Use doc_type "proposal" for a pitch, "estimate" for a quick quote.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'doc_type'     => ['type' => 'string', 'description' => 'proposal|estimate.'],
                        'title'        => ['type' => 'string', 'description' => 'Short title, e.g. "Website redesign".'],
                        'buyer_name'   => ['type' => 'string', 'description' => 'Optional buyer/customer name.'],
                        'company_name' => ['type' => 'string', 'description' => 'Optional company to link.'],
                        'tax_rate'     => ['type' => 'number', 'description' => 'Optional whole-doc tax rate percent, e.g. 18.'],
                        'valid_until'  => ['type' => 'string', 'description' => 'Optional expiry date, YYYY-MM-DD.'],
                        'items'        => [
                            'type' => 'array',
                            'description' => 'Line items.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'description' => ['type' => 'string'],
                                    'qty'         => ['type' => 'number'],
                                    'unit_price'  => ['type' => 'number', 'description' => 'Price per unit in major units (e.g. 49.99).'],
                                ],
                                'required' => ['description', 'qty', 'unit_price'],
                            ],
                        ],
                    ],
                    'required' => ['doc_type', 'items'],
                ],
            ],
            [
                'name' => 'list_quotes',
                'kind' => 'read',
                'description' => 'List recent proposals/estimates with their status and total. Optionally filter by doc_type (proposal|estimate).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'doc_type' => ['type' => 'string', 'description' => 'Optional: proposal|estimate.'],
                    ],
                ],
            ],
            // ---- messaging tools (send through the workspace's own WhatsApp) ----
            [
                'name' => 'send_message',
                'kind' => 'write',
                'description' => 'Send a plain WhatsApp text message to a customer through the workspace\'s connected WhatsApp number. Give either a phone number or the name of an existing contact.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'to'      => ['type' => 'string', 'description' => 'Phone number (digits) OR an existing contact name.'],
                        'message' => ['type' => 'string', 'description' => 'The text to send.'],
                    ],
                    'required' => ['to', 'message'],
                ],
            ],
            [
                'name' => 'send_quote',
                'kind' => 'write',
                'description' => 'Send an existing proposal or estimate to its buyer over WhatsApp (the public accept link). Identify it by its number (e.g. EST-0001) or by title.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'number' => ['type' => 'string', 'description' => 'The quote number, e.g. EST-0001 or PRO-0002.'],
                        'title'  => ['type' => 'string', 'description' => 'Alternatively, part of the quote title to match.'],
                    ],
                ],
            ],
        ];
    }

    /** kind ('read'|'write') for a tool name, or null if unknown. */
    public function kindOf(string $tool): ?string
    {
        foreach ($this->definitions() as $d) {
            if ($d['name'] === $tool) return $d['kind'];
        }
        return null;
    }

    /**
     * Execute a tool. Returns ['ok'=>bool, 'summary'=>string, 'data'=>array,
     * 'subject_type'=>?string, 'subject_id'=>?int]. Never throws.
     */
    public function execute(string $tool, array $args): array
    {
        try {
            return match ($tool) {
                'find_contact'    => $this->findContact($args),
                'create_contact'  => $this->createContact($args),
                'list_deals'      => $this->listDeals($args),
                'create_deal'     => $this->createDeal($args),
                'move_deal_stage' => $this->moveDealStage($args),
                'find_company'         => $this->findCompany($args),
                'create_company'       => $this->createCompany($args),
                'link_contact_company' => $this->linkContactCompany($args),
                'sales_report'    => $this->salesReport(),
                'create_invoice'  => $this->createInvoice($args),
                'record_payment'  => $this->recordPayment($args),
                'list_outstanding'=> $this->listOutstanding(),
                'create_task'     => $this->createTask($args),
                'assign_task'     => $this->assignTask($args),
                'complete_task'   => $this->completeTask($args),
                'list_my_tasks'   => $this->listMyTasks(),
                'revenue_report'  => $this->revenueReport(),
                'generate_brief'  => $this->generateBrief($args),
                'create_project'  => $this->createProject($args),
                'list_projects'   => $this->listProjects(),
                'create_quote'    => $this->createQuote($args),
                'list_quotes'     => $this->listQuotes($args),
                'send_message'    => $this->sendMessage($args),
                'send_quote'      => $this->sendQuote($args),
                default           => ['ok' => false, 'summary' => "Unknown tool: {$tool}", 'data' => []],
            };
        } catch (\Throwable $e) {
            Log::warning("[AI-CRM] tool {$tool} failed: " . $e->getMessage());
            return ['ok' => false, 'summary' => 'Tool failed: ' . $e->getMessage(), 'data' => []];
        }
    }

    // ---- read tools ---------------------------------------------------------

    private function findContact(array $args): array
    {
        $wsId  = $this->ctx['workspace_id'];
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') return ['ok' => false, 'summary' => 'Empty search query.', 'data' => []];

        $digits = preg_replace('/\D+/', '', $query);
        $rows = [];

        // Phone lookup uses the indexed hash; name lookup decrypts a bounded scan.
        $candidates = Contact::where('workspace_id', $wsId)->latest('id')->limit(500)->get();
        foreach ($candidates as $c) {
            $stored = Contact::canonicalizePhone($c->country_code, $c->mobile);
            $name   = (string) $c->name;
            $match  = ($digits !== '' && $stored !== '' && str_contains($stored, $digits))
                || ($name !== '' && stripos($name, $query) !== false);
            if ($match) {
                $rows[] = [
                    'id'         => $c->id,
                    'name'       => $name ?: '(no name)',
                    'phone'      => self::maskPhone($stored),
                    'open_deals' => (int) $c->deals()->where('status', 'open')->count(),
                ];
            }
            if (count($rows) >= 10) break;
        }

        return [
            'ok' => true,
            'summary' => count($rows) . ' contact(s) found for "' . $query . '".',
            'data' => ['contacts' => $rows],
        ];
    }

    private function listDeals(array $args): array
    {
        $wsId   = $this->ctx['workspace_id'];
        $status = strtolower((string) ($args['status'] ?? 'open'));
        $limit  = min(50, max(1, (int) ($args['limit'] ?? 15)));
        $stage  = trim((string) ($args['stage'] ?? ''));

        $q = Deal::where('workspace_id', $wsId);
        if (in_array($status, ['open', 'won', 'lost'], true)) {
            $q->where('status', $status);
        }
        if ($stage !== '') {
            $stageIds = PipelineStage::where('workspace_id', $wsId)
                ->where('name', 'like', '%' . $stage . '%')->pluck('id');
            $q->whereIn('stage_id', $stageIds);
        }
        $deals = $q->orderByDesc('id')->limit($limit)->get();

        $stageNames = PipelineStage::where('workspace_id', $wsId)->pluck('name', 'id');
        $rows = $deals->map(fn ($d) => [
            'id'     => $d->id,
            'title'  => $d->title,
            'value'  => round($d->value_minor / 100, 2),
            'currency' => $d->currency,
            'status' => $d->status,
            'stage'  => $stageNames[$d->stage_id] ?? '—',
        ])->all();

        return [
            'ok' => true,
            'summary' => count($rows) . ' ' . ($status === 'any' ? '' : $status . ' ') . 'deal(s).',
            'data' => ['deals' => $rows],
        ];
    }

    private function salesReport(): array
    {
        $wsId   = $this->ctx['workspace_id'];
        $stages = PipelineStage::where('workspace_id', $wsId)->get()->keyBy('id');

        $open = Deal::where('workspace_id', $wsId)->where('status', 'open')->get();
        $openValue = 0;
        $weighted  = 0.0;
        foreach ($open as $d) {
            $openValue += $d->value_minor;
            $prob = (float) ($stages[$d->stage_id]->probability ?? 0);
            $weighted += $d->value_minor * ($prob / 100);
        }

        $wonMonth = Deal::where('workspace_id', $wsId)->where('status', 'won')
            ->where('won_at', '>=', now()->startOfMonth())->get();
        $wonMonthValue = (int) $wonMonth->sum('value_minor');

        $wonAll  = Deal::where('workspace_id', $wsId)->where('status', 'won')->count();
        $lostAll = Deal::where('workspace_id', $wsId)->where('status', 'lost')->count();
        $winRate = ($wonAll + $lostAll) > 0 ? round($wonAll / ($wonAll + $lostAll) * 100) : 0;

        return [
            'ok' => true,
            'summary' => 'Sales summary computed.',
            'data' => [
                'open_deals'          => $open->count(),
                'open_pipeline_value' => round($openValue / 100, 2),
                'weighted_forecast'   => round($weighted / 100, 2),
                'won_this_month'      => $wonMonth->count(),
                'won_this_month_value'=> round($wonMonthValue / 100, 2),
                'win_rate_pct'        => $winRate,
            ],
        ];
    }

    // ---- write tools --------------------------------------------------------

    private function createContact(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $name = trim((string) ($args['name'] ?? ''));
        $phone = trim((string) ($args['phone'] ?? ''));
        $cc = trim((string) ($args['country_code'] ?? '')) ?: null;
        $email = trim((string) ($args['email'] ?? '')) ?: null;

        if (preg_replace('/\D+/', '', $phone) === '') {
            return ['ok' => false, 'summary' => 'A valid phone number is required.', 'data' => []];
        }

        $contact = Contact::rememberPhone($wsId, $this->ctx['user_id'], $phone, $name ?: null, $cc);
        if (!$contact) {
            return ['ok' => false, 'summary' => 'Could not create the contact.', 'data' => []];
        }
        // rememberPhone won't overwrite an existing name/email — set if provided.
        $dirty = false;
        if ($name !== '' && (string) $contact->name === '') { $contact->name = $name; $dirty = true; }
        if ($email && (string) $contact->email === '') { $contact->email = $email; $dirty = true; }
        if ($dirty) $contact->save();

        return [
            'ok' => true,
            'summary' => 'Contact "' . ($name ?: $contact->name ?: 'contact') . '" saved (#' . $contact->id . ').',
            'data' => ['contact_id' => $contact->id, 'name' => (string) $contact->name],
            'subject_type' => 'Contact',
            'subject_id' => $contact->id,
        ];
    }

    private function createDeal(array $args): array
    {
        $wsId  = $this->ctx['workspace_id'];
        $title = trim((string) ($args['title'] ?? '')) ?: 'New deal';
        $value = (float) ($args['value'] ?? 0);
        $phone = trim((string) ($args['contact_phone'] ?? ''));
        $stageName = trim((string) ($args['stage'] ?? ''));

        $pipeline = Pipeline::ensureDefaultForWorkspace($wsId);
        $stage = $this->resolveStage($wsId, $pipeline->id, $stageName, false);
        if (!$stage) return ['ok' => false, 'summary' => 'No pipeline stage available.', 'data' => []];

        $contactId = null;
        if ($phone !== '') {
            $contact = Contact::rememberPhone($wsId, $this->ctx['user_id'], $phone, null, null);
            $contactId = $contact?->id;
        }

        $deal = Deal::create([
            'workspace_id'  => $wsId,
            'pipeline_id'   => $pipeline->id,
            'stage_id'      => $stage->id,
            'contact_id'    => $contactId,
            'title'         => mb_substr($title, 0, 191),
            'value_minor'   => (int) round($value * 100),
            'currency'      => $pipeline->currency,
            'owner_user_id' => $this->ctx['user_id'],
            'source'        => 'api',
            'sort_order'    => 0,
        ]);

        return [
            'ok' => true,
            'summary' => 'Deal "' . $title . '" created (#' . $deal->id . ') in stage "' . $stage->name . '".',
            'data' => ['deal_id' => $deal->id, 'stage' => $stage->name, 'status' => $deal->status],
            'subject_type' => 'Deal',
            'subject_id' => $deal->id,
        ];
    }

    private function moveDealStage(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $dealId = (int) ($args['deal_id'] ?? 0);
        $stageName = trim((string) ($args['stage'] ?? ''));

        $deal = Deal::where('workspace_id', $wsId)->find($dealId);
        if (!$deal) return ['ok' => false, 'summary' => "Deal #{$dealId} not found.", 'data' => []];

        $stage = $this->resolveStage($wsId, $deal->pipeline_id, $stageName, true);
        if (!$stage) return ['ok' => false, 'summary' => "No stage matching \"{$stageName}\".", 'data' => []];

        $deal->update(['stage_id' => $stage->id]); // observer syncs status/won_at/lost_at
        $deal->refresh();

        return [
            'ok' => true,
            'summary' => 'Deal #' . $deal->id . ' moved to "' . $stage->name . '" (status: ' . $deal->status . ').',
            'data' => ['deal_id' => $deal->id, 'stage' => $stage->name, 'status' => $deal->status],
            'subject_type' => 'Deal',
            'subject_id' => $deal->id,
        ];
    }

    // ---- company tools ------------------------------------------------------

    private function findCompany(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $q = trim((string) ($args['query'] ?? ''));
        if ($q === '') return ['ok' => false, 'summary' => 'Empty company query.', 'data' => []];

        $rows = [];
        foreach (Company::where('workspace_id', $wsId)->latest('id')->limit(300)->get() as $c) {
            if (stripos((string) $c->name, $q) === false) continue;
            $rows[] = [
                'id'         => $c->id,
                'name'       => (string) $c->name,
                'industry'   => (string) ($c->industry ?? ''),
                'contacts'   => (int) $c->contacts()->count(),
                'open_deals' => (int) $c->deals()->where('status', 'open')->count(),
            ];
            if (count($rows) >= 10) break;
        }
        return ['ok' => true, 'summary' => count($rows) . ' company(ies) found for "' . $q . '".', 'data' => ['companies' => $rows]];
    }

    private function createCompany(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'summary' => 'A company name is required.', 'data' => []];

        $company = Company::create([
            'workspace_id' => $wsId,
            'user_id'      => $this->ctx['user_id'],
            'name'         => $name,
            'industry'     => trim((string) ($args['industry'] ?? '')) ?: null,
            'website'      => trim((string) ($args['website'] ?? '')) ?: null,
            'email'        => trim((string) ($args['email'] ?? '')) ?: null,
            'phone'        => trim((string) ($args['phone'] ?? '')) ?: null,
        ]);
        return [
            'ok'           => true,
            'summary'      => 'Company "' . $name . '" created (#' . $company->id . ').',
            'data'         => ['company_id' => $company->id, 'name' => $name],
            'subject_type' => 'Company',
            'subject_id'   => $company->id,
        ];
    }

    private function linkContactCompany(array $args): array
    {
        $wsId        = $this->ctx['workspace_id'];
        $phone       = trim((string) ($args['contact_phone'] ?? ''));
        $companyName = trim((string) ($args['company_name'] ?? ''));
        if (preg_replace('/\D+/', '', $phone) === '' || $companyName === '') {
            return ['ok' => false, 'summary' => 'A contact phone and a company name are required.', 'data' => []];
        }

        $contact = Contact::rememberPhone($wsId, $this->ctx['user_id'], $phone, null, null);
        if (!$contact) return ['ok' => false, 'summary' => 'Could not resolve the contact.', 'data' => []];

        // Find (case-insensitive) or create the company by name.
        $company = Company::where('workspace_id', $wsId)->get()
            ->first(fn ($c) => strcasecmp((string) $c->name, $companyName) === 0);
        if (!$company) {
            $company = Company::create(['workspace_id' => $wsId, 'user_id' => $this->ctx['user_id'], 'name' => $companyName]);
        }

        $contact->company_id = $company->id;
        $contact->save();

        return [
            'ok'           => true,
            'summary'      => 'Linked contact ' . self::maskPhone(preg_replace('/\D+/', '', $phone)) . ' to company "' . $company->name . '".',
            'data'         => ['contact_id' => $contact->id, 'company_id' => $company->id],
            'subject_type' => 'Company',
            'subject_id'   => $company->id,
        ];
    }

    // ---- helpers ------------------------------------------------------------

    /** Resolve a stage by name fragment within a pipeline; fall back to first stage. */
    // ---- Phase 2 money tools ------------------------------------------------

    private const ZERO_DEC = ['JPY', 'KRW', 'VND', 'IDR', 'CLP', 'ISK', 'HUF', 'XAF', 'XOF', 'PYG', 'RWF', 'UGX', 'KMF'];

    private function wsCurrency(int $wsId): string
    {
        return strtoupper((string) (\App\Models\InvoiceSetting::forWorkspace($wsId)->currency ?? 'USD')) ?: 'USD';
    }

    private function expFor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DEC, true) ? 0 : 2;
    }

    private function createInvoice(array $args): array
    {
        $wsId  = $this->ctx['workspace_id'];
        $ws    = \App\Models\Workspace::find($wsId);
        $buyer = trim((string) ($args['buyer_name'] ?? ''));
        $items = is_array($args['items'] ?? null) ? $args['items'] : [];
        if (! $ws || $buyer === '' || empty($items)) {
            return ['ok' => false, 'summary' => 'Need a buyer name and at least one line item.', 'data' => []];
        }
        $currency = strtoupper((string) ($args['currency'] ?? $this->wsCurrency($wsId))) ?: 'USD';
        $exp  = $this->expFor($currency);
        $unit = 10 ** $exp;

        $lines = []; $subtotal = 0; $tax = 0; $byRate = [];
        foreach ($items as $row) {
            $qty     = (float) ($row['qty'] ?? 1);
            $price   = (int) round(((float) ($row['unit_price'] ?? 0)) * $unit);
            $lineSub = (int) round($price * $qty);
            $rate    = (float) ($row['tax_rate'] ?? 0);
            $lineTax = (int) round($lineSub * $rate / 100);
            $lines[] = ['description' => (string) ($row['description'] ?? 'Item'), 'sku' => null, 'hsn_sac' => null,
                'qty' => $qty, 'unit_price_minor' => $price, 'line_subtotal_minor' => $lineSub,
                'line_discount_minor' => 0, 'tax_rate' => $rate, 'tax_amount_minor' => $lineTax, 'tax_code' => null];
            $subtotal += $lineSub; $tax += $lineTax;
            if ($rate > 0) {
                $k = (string) $rate;
                $byRate[$k] = $byRate[$k] ?? ['label' => 'Tax ' . $rate . '%', 'rate' => $rate, 'base_minor' => 0, 'amount_minor' => 0];
                $byRate[$k]['base_minor'] += $lineSub; $byRate[$k]['amount_minor'] += $lineTax;
            }
        }
        $total = max(0, $subtotal + $tax);

        $draft = new \App\Services\Invoice\InvoiceDraft(
            source: 'manual', docType: 'tax_invoice', currency: $currency, currencyExponent: $exp,
            buyer: array_filter(['name' => $buyer, 'phone' => $args['buyer_phone'] ?? null]),
            items: $lines, taxSummary: array_values($byRate),
            subtotalMinor: $subtotal, taxMinor: $tax, totalMinor: $total,
            waOrderId: null, trigger: 'manual', meta: ['via' => 'copilot'],
        );
        $svc     = app(\App\Services\Invoice\InvoiceService::class);
        $invoice = $svc->issue($draft, $ws, $this->ctx['user_id']);
        if (! $invoice) {
            return ['ok' => false, 'summary' => 'Invoicing is not enabled on this plan.', 'data' => []];
        }
        $svc->renderAndSend($invoice);

        return [
            'ok' => true,
            'summary' => 'Created invoice ' . $invoice->invoice_number . ' for ' . $buyer . ' — total '
                . \App\Models\Currency::symbolFor($currency) . number_format($total / 100, 2) . '.',
            'data' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'total' => round($total / 100, 2)],
            'subject_type' => 'invoice', 'subject_id' => $invoice->id,
        ];
    }

    private function recordPayment(array $args): array
    {
        $wsId   = $this->ctx['workspace_id'];
        $amount = (float) ($args['amount'] ?? 0);
        if ($amount <= 0) {
            return ['ok' => false, 'summary' => 'Amount must be positive.', 'data' => []];
        }
        $invoice = null;
        if (! empty($args['invoice_number'])) {
            $invoice = \App\Models\Invoice::forWorkspace($wsId)->where('invoice_number', (string) $args['invoice_number'])->first();
        }
        $currency = strtoupper((string) ($args['currency'] ?? ($invoice->currency ?? $this->wsCurrency($wsId)))) ?: 'USD';
        $exp = $invoice ? (int) ($invoice->currency_exponent ?? 2) : $this->expFor($currency);

        $contactId = null;
        if (! empty($args['contact_phone'])) {
            $digits = preg_replace('/\D+/', '', (string) $args['contact_phone']);
            $contactId = Contact::where('workspace_id', $wsId)->orderByDesc('id')->get(['id', 'mobile'])
                ->first(fn ($c) => preg_replace('/\D+/', '', (string) $c->mobile) === $digits)?->id;
        }

        $payment = app(\App\Services\Crm\PaymentLedger::class)->record($wsId, [
            'amount_minor' => (int) round($amount * (10 ** $exp)),
            'currency' => $currency, 'method' => $args['method'] ?? 'manual', 'source' => 'manual',
            'invoice_id' => $invoice?->id, 'contact_id' => $contactId, 'wa_order_id' => $invoice?->wa_order_id,
            'recorded_by' => $this->ctx['user_id'],
        ]);
        $sfx = $invoice ? (' against ' . $invoice->invoice_number) : '';
        return [
            'ok' => true,
            'summary' => 'Recorded ' . \App\Models\Currency::symbolFor($currency) . number_format($amount, 2) . $sfx . '.',
            'data' => ['payment_id' => $payment->id],
            'subject_type' => 'payment', 'subject_id' => $payment->id,
        ];
    }

    private function listOutstanding(): array
    {
        $wsId   = $this->ctx['workspace_id'];
        $ledger = app(\App\Services\Crm\PaymentLedger::class);
        $aging  = $ledger->aging($wsId);
        $top = \App\Models\Invoice::forWorkspace($wsId)->where('status', \App\Models\Invoice::STATUS_ISSUED)
            ->orderByDesc('id')->limit(50)->get(['id', 'invoice_number', 'total_minor', 'currency', 'created_at', 'currency_exponent'])
            ->map(fn ($inv) => ['invoice_number' => $inv->invoice_number, 'outstanding' => round($ledger->outstandingMinor($inv) / 100, 2), 'currency' => $inv->currency])
            ->filter(fn ($r) => $r['outstanding'] > 0)->take(10)->values()->all();

        return [
            'ok' => true,
            'summary' => $aging['count'] . ' invoices outstanding, total '
                . \App\Models\Currency::symbolFor($aging['currency']) . number_format($aging['total_outstanding_minor'] / 100, 2) . '.',
            'data' => ['aging' => $aging['buckets'], 'total_outstanding' => round($aging['total_outstanding_minor'] / 100, 2), 'invoices' => $top],
        ];
    }

    // ---- Phase 3 task tools -------------------------------------------------

    private function memberByName(int $wsId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;
        $ids = \Illuminate\Support\Facades\DB::table('workspace_user')->where('workspace_id', $wsId)->pluck('user_id')->all();
        return \App\Models\User::whereIn('id', $ids)->get(['id', 'name'])
            ->first(fn ($u) => $name !== '' && stripos((string) $u->name, $name) !== false)?->id;
    }

    private function createTask(array $args): array
    {
        $wsId  = $this->ctx['workspace_id'];
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') return ['ok' => false, 'summary' => 'Task needs a title.', 'data' => []];

        $assignee = $this->ctx['user_id'];
        if (! empty($args['assignee_name'])) {
            $m = $this->memberByName($wsId, (string) $args['assignee_name']);
            if ($m) $assignee = $m;
        }
        $due = null;
        if (! empty($args['due_at'])) {
            try { $due = \Illuminate\Support\Carbon::parse((string) $args['due_at']); } catch (\Throwable $e) {}
        }
        $task = \App\Models\Task::create([
            'workspace_id' => $wsId, 'created_by' => $this->ctx['user_id'], 'assignee_id' => $assignee,
            'title' => $title,
            'priority' => in_array($args['priority'] ?? 'medium', \App\Models\Task::PRIORITIES, true) ? $args['priority'] : 'medium',
            'status' => 'open', 'due_at' => $due,
        ]);
        if ($assignee && $assignee !== $this->ctx['user_id']) {
            try { app(\App\Services\Inbox\NotificationDispatcher::class)->notifyTaskAssigned($task, $this->ctx['user_id']); } catch (\Throwable $e) {}
        }
        return [
            'ok' => true,
            'summary' => 'Created task "' . $title . '"' . ($due ? (' due ' . $due->format('d M H:i')) : '') . '.',
            'data' => ['task_id' => $task->id], 'subject_type' => 'task', 'subject_id' => $task->id,
        ];
    }

    private function assignTask(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $task = \App\Models\Task::forWorkspace($wsId)->find((int) ($args['task_id'] ?? 0));
        if (! $task) return ['ok' => false, 'summary' => 'Task not found.', 'data' => []];
        $m = $this->memberByName($wsId, (string) ($args['assignee_name'] ?? ''));
        if (! $m) return ['ok' => false, 'summary' => 'No workspace member matches "' . ($args['assignee_name'] ?? '') . '".', 'data' => []];
        $task->forceFill(['assignee_id' => $m])->save();
        try { app(\App\Services\Inbox\NotificationDispatcher::class)->notifyTaskAssigned($task, $this->ctx['user_id']); } catch (\Throwable $e) {}
        return ['ok' => true, 'summary' => 'Assigned task #' . $task->id . '.', 'data' => ['task_id' => $task->id], 'subject_type' => 'task', 'subject_id' => $task->id];
    }

    private function completeTask(array $args): array
    {
        $task = \App\Models\Task::forWorkspace($this->ctx['workspace_id'])->find((int) ($args['task_id'] ?? 0));
        if (! $task) return ['ok' => false, 'summary' => 'Task not found.', 'data' => []];
        $task->forceFill(['status' => 'done', 'done_at' => now()])->save();
        return ['ok' => true, 'summary' => 'Marked task #' . $task->id . ' done.', 'data' => ['task_id' => $task->id], 'subject_type' => 'task', 'subject_id' => $task->id];
    }

    private function listMyTasks(): array
    {
        $wsId = $this->ctx['workspace_id'];
        $me   = $this->ctx['user_id'];
        $tasks = \App\Models\Task::forWorkspace($wsId)->where('status', 'open')
            ->where(fn ($w) => $w->where('assignee_id', $me)->orWhereNull('assignee_id'))
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(25)->get();
        $rows = $tasks->map(fn ($t) => [
            'id' => $t->id, 'title' => $t->title, 'priority' => $t->priority,
            'due' => optional($t->due_at)->format('Y-m-d H:i'), 'overdue' => $t->isOverdue(),
        ])->all();
        return ['ok' => true, 'summary' => $tasks->count() . ' open task(s).', 'data' => ['tasks' => $rows]];
    }

    // ---- Phase 4 revenue tool -----------------------------------------------

    private function revenueReport(): array
    {
        $wsId   = $this->ctx['workspace_id'];
        $ledger = app(\App\Services\Crm\PaymentLedger::class);
        $aging  = $ledger->aging($wsId);
        $cur    = $this->wsCurrency($wsId);
        $sym    = \App\Models\Currency::symbolFor($cur);

        $collected30 = (int) \App\Models\Payment::forWorkspace($wsId)->where('paid_at', '>=', now()->subDays(30))->sum('amount_minor');
        $collectedAll= (int) \App\Models\Payment::forWorkspace($wsId)->sum('amount_minor');
        $tax         = (int) \App\Models\Invoice::forWorkspace($wsId)->where('status', \App\Models\Invoice::STATUS_PAID)->sum('tax_minor');

        return [
            'ok' => true,
            'summary' => 'Collected ' . $sym . number_format($collected30 / 100, 2) . ' in 30 days; '
                . $sym . number_format(($aging['total_outstanding_minor']) / 100, 2) . ' outstanding across ' . $aging['count'] . ' invoices.',
            'data' => [
                'currency'         => $cur,
                'collected_30d'    => round($collected30 / 100, 2),
                'collected_all'    => round($collectedAll / 100, 2),
                'outstanding'      => round($aging['total_outstanding_minor'] / 100, 2),
                'tax_collected'    => round($tax / 100, 2),
                'aging'            => $aging['buckets'],
                'invoices_paid'    => (int) \App\Models\Invoice::forWorkspace($wsId)->where('status', \App\Models\Invoice::STATUS_PAID)->count(),
                'invoices_open'    => (int) \App\Models\Invoice::forWorkspace($wsId)->where('status', \App\Models\Invoice::STATUS_ISSUED)->count(),
            ],
        ];
    }

    // ---- Phase 5 brief tool -------------------------------------------------

    private function generateBrief(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $type = strtolower(trim((string) ($args['subject_type'] ?? '')));
        $name = trim((string) ($args['name'] ?? ''));
        if (! in_array($type, ['company', 'contact'], true) || $name === '') {
            return ['ok' => false, 'summary' => 'Tell me a company or contact name for the brief.', 'data' => []];
        }

        $subjectId = null;
        if ($type === 'company' && class_exists(Company::class)) {
            $subjectId = Company::where('workspace_id', $wsId)->where('name', 'like', '%' . $name . '%')->orderBy('id')->value('id');
        } elseif ($type === 'contact') {
            $subjectId = Contact::where('workspace_id', $wsId)->where('name', 'like', '%' . $name . '%')->orderByDesc('id')->value('id');
        }
        if (! $subjectId) {
            return ['ok' => false, 'summary' => "No {$type} matches \"{$name}\".", 'data' => []];
        }

        $brief = app(\App\Services\Crm\BriefService::class)->generate($wsId, $type, (int) $subjectId, $this->ctx['user_id']);
        if (! $brief) {
            return ['ok' => false, 'summary' => 'Could not generate the brief.', 'data' => []];
        }
        return [
            'ok' => true,
            'summary' => 'Brief ready: ' . $brief->publicUrl(),
            'data' => ['url' => $brief->publicUrl(), 'brief_id' => $brief->id],
            'subject_type' => 'brief', 'subject_id' => $brief->id,
        ];
    }

    // ---- Phase 6 project tools ----------------------------------------------

    /** Resolve a company by (encrypted) name fragment within the workspace. */
    private function companyByName(int $wsId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;
        foreach (Company::where('workspace_id', $wsId)->latest('id')->limit(300)->get(['id', 'name']) as $c) {
            if (stripos((string) $c->name, $name) !== false) return (int) $c->id;
        }
        return null;
    }

    private function createProject(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'summary' => 'A project name is required.', 'data' => []];

        $companyId = ! empty($args['company_name']) ? $this->companyByName($wsId, (string) $args['company_name']) : null;
        $ownerId = ! empty($args['owner_name']) ? $this->memberByName($wsId, (string) $args['owner_name']) : $this->ctx['user_id'];
        $due = null;
        if (! empty($args['due_date'])) {
            try { $due = \Illuminate\Support\Carbon::parse((string) $args['due_date'])->toDateString(); } catch (\Throwable $e) {}
        }

        $project = \App\Models\Project::create([
            'workspace_id' => $wsId,
            'name'         => mb_substr($name, 0, 255),
            'status'       => 'in_progress',
            'progress'     => 0,
            'company_id'   => $companyId,
            'owner_id'     => $ownerId ?: $this->ctx['user_id'],
            'created_by'   => $this->ctx['user_id'],
            'due_date'     => $due,
        ]);

        return [
            'ok' => true,
            'summary' => 'Created project "' . $name . '"' . ($due ? (' due ' . $due) : '') . '.',
            'data' => ['project_id' => $project->id], 'subject_type' => 'project', 'subject_id' => $project->id,
        ];
    }

    private function listProjects(): array
    {
        $wsId = $this->ctx['workspace_id'];
        $active = \App\Models\Project::where('workspace_id', $wsId)->where('status', 'in_progress')
            ->orderByDesc('id')->limit(25)->get(['id', 'name', 'progress', 'due_date']);
        $overdue = (int) \App\Models\Project::where('workspace_id', $wsId)->where('status', 'in_progress')
            ->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString())->count();
        $completed = (int) \App\Models\Project::where('workspace_id', $wsId)->where('status', 'completed')->count();

        $rows = $active->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'progress' => $p->progress . '%',
            'due' => $p->due_date ? $p->due_date->format('Y-m-d') : null,
        ])->all();

        return [
            'ok' => true,
            'summary' => $active->count() . ' active project(s), ' . $overdue . ' overdue, ' . $completed . ' completed.',
            'data' => ['active' => $rows, 'overdue' => $overdue, 'completed' => $completed],
        ];
    }

    // ---- Phase 7 quote tools (proposals + estimates) ------------------------

    private function createQuote(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $type = ($args['doc_type'] ?? '') === 'estimate'
            ? \App\Models\SalesDoc::TYPE_ESTIMATE
            : \App\Models\SalesDoc::TYPE_PROPOSAL;

        $rawItems = is_array($args['items'] ?? null) ? $args['items'] : [];
        if (empty($rawItems)) return ['ok' => false, 'summary' => 'A quote needs at least one line item.', 'data' => []];

        $currency = $this->wsCurrency($wsId);
        $exp = $this->expFor($currency);
        $mult = 10 ** $exp;

        $items = [];
        $subtotal = 0;
        foreach ($rawItems as $row) {
            $desc = trim((string) ($row['description'] ?? ''));
            if ($desc === '') continue;
            $qty = (float) ($row['qty'] ?? 1);
            $unitMinor = (int) round(((float) ($row['unit_price'] ?? 0)) * $mult);
            $lineMinor = (int) round($qty * $unitMinor);
            $subtotal += $lineMinor;
            $items[] = ['description' => $desc, 'qty' => $qty, 'unit_price_minor' => $unitMinor, 'line_total_minor' => $lineMinor];
        }
        if (empty($items)) return ['ok' => false, 'summary' => 'No valid line items.', 'data' => []];

        $taxRateBp = (int) round(((float) ($args['tax_rate'] ?? 0)) * 100);
        $taxMinor = (int) round($subtotal * $taxRateBp / 10000);
        $total = $subtotal + $taxMinor;

        $companyId = ! empty($args['company_name']) ? $this->companyByName($wsId, (string) $args['company_name']) : null;
        $validUntil = null;
        if (! empty($args['valid_until'])) {
            try { $validUntil = \Illuminate\Support\Carbon::parse((string) $args['valid_until'])->toDateString(); } catch (\Throwable $e) {}
        }

        $seq = (int) \App\Models\SalesDoc::where('workspace_id', $wsId)->where('doc_type', $type)->max('seq') + 1;
        $prefix = $type === \App\Models\SalesDoc::TYPE_ESTIMATE ? 'EST-' : 'PRO-';

        $doc = \App\Models\SalesDoc::create([
            'workspace_id'      => $wsId,
            'doc_type'          => $type,
            'number'            => $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'seq'               => $seq,
            'status'            => \App\Models\SalesDoc::STATUS_DRAFT,
            'title'             => trim((string) ($args['title'] ?? '')) ?: null,
            'company_id'        => $companyId,
            'owner_id'          => $this->ctx['user_id'],
            'created_by'        => $this->ctx['user_id'],
            'buyer_name'        => trim((string) ($args['buyer_name'] ?? '')) ?: null,
            'currency'          => $currency,
            'currency_exponent' => $exp,
            'subtotal_minor'    => $subtotal,
            'discount_minor'    => 0,
            'tax_minor'         => $taxMinor,
            'total_minor'       => $total,
            'tax_rate_bp'       => $taxRateBp,
            'items_json'        => $items,
            'valid_until'       => $validUntil,
            'public_token'      => \Illuminate\Support\Str::random(40),
        ]);

        $sym = \App\Models\Currency::symbolFor($currency);
        return [
            'ok' => true,
            'summary' => 'Created ' . $doc->typeLabel() . ' ' . $doc->number . ' for '
                . $sym . number_format($total / $mult, $exp) . '. Share link: ' . $doc->publicUrl(),
            'data' => ['sales_doc_id' => $doc->id, 'number' => $doc->number, 'public_url' => $doc->publicUrl(), 'total' => round($total / $mult, $exp)],
            'subject_type' => 'sales_doc', 'subject_id' => $doc->id,
        ];
    }

    private function listQuotes(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $q = \App\Models\SalesDoc::where('workspace_id', $wsId);
        if (in_array($args['doc_type'] ?? '', \App\Models\SalesDoc::TYPES, true)) {
            $q->where('doc_type', $args['doc_type']);
        }
        $docs = $q->orderByDesc('id')->limit(20)->get();
        $rows = $docs->map(fn ($d) => [
            'number' => $d->number, 'type' => $d->doc_type, 'title' => $d->title,
            'status' => $d->status, 'total' => $d->total_display, 'link' => $d->publicUrl(),
        ])->all();
        return ['ok' => true, 'summary' => $docs->count() . ' quote(s).', 'data' => ['quotes' => $rows]];
    }

    // ---- messaging tools ----------------------------------------------------

    /** Send a WhatsApp text through the workspace's own engine. Returns [ok,error]. */
    private function dispatchWhatsApp(int $wsId, string $toDigits, string $body): array
    {
        $msg = \App\Models\Message::create([
            'user_id'      => $this->ctx['user_id'],
            'workspace_id' => $wsId,
            'direction'    => 'out',
            'to_number'    => $toDigits,
            'body'         => $body,
            'status'       => 'pending',
        ]);
        try {
            $res = app(\App\Services\WhatsAppDispatcher::class)->send($msg);
            $ok = (bool) ($res['ok'] ?? false);
            $msg->forceFill(['status' => $ok ? 'sent' : 'failed', 'failure_reason' => $ok ? null : ($res['error'] ?? null), 'sent_at' => $ok ? now() : null])->save();
            return ['ok' => $ok, 'error' => $res['error'] ?? null];
        } catch (\Throwable $e) {
            $msg->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)])->save();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendMessage(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $to = trim((string) ($args['to'] ?? ''));
        $text = trim((string) ($args['message'] ?? ''));
        if ($to === '' || $text === '') return ['ok' => false, 'summary' => 'Need both a recipient and a message.', 'data' => []];

        // Resolve recipient: raw digits, or a contact name → its phone.
        $digits = preg_replace('/\D+/', '', $to);
        if (strlen($digits) < 6) {
            $contact = null;
            foreach (Contact::where('workspace_id', $wsId)->latest('id')->limit(500)->get() as $c) {
                if (stripos((string) $c->name, $to) !== false) { $contact = $c; break; }
            }
            if (! $contact) return ['ok' => false, 'summary' => 'No contact matches "' . $to . '", and that is not a phone number.', 'data' => []];
            $digits = preg_replace('/\D+/', '', (string) $contact->phone);
            if ($digits === '') return ['ok' => false, 'summary' => 'Contact "' . $contact->name . '" has no phone number.', 'data' => []];
        }

        $res = $this->dispatchWhatsApp($wsId, $digits, $text);
        if (! $res['ok']) return ['ok' => false, 'summary' => 'Could not send: ' . ($res['error'] ?: 'no WhatsApp engine connected.'), 'data' => []];

        return ['ok' => true, 'summary' => 'Sent WhatsApp message to ' . self::maskPhone($digits) . '.', 'data' => ['to' => self::maskPhone($digits)]];
    }

    private function sendQuote(array $args): array
    {
        $wsId = $this->ctx['workspace_id'];
        $number = trim((string) ($args['number'] ?? ''));
        $title = trim((string) ($args['title'] ?? ''));

        $q = \App\Models\SalesDoc::where('workspace_id', $wsId);
        if ($number !== '') {
            $doc = (clone $q)->where('number', 'like', '%' . $number . '%')->orderByDesc('id')->first();
        } else {
            $doc = (clone $q)->where('title', 'like', '%' . $title . '%')->orderByDesc('id')->first();
        }
        if (! $doc) return ['ok' => false, 'summary' => 'No matching proposal/estimate found.', 'data' => []];

        $to = preg_replace('/\D+/', '', (string) $doc->buyer_phone);
        if ($to === '') return ['ok' => false, 'summary' => $doc->typeLabel() . ' ' . $doc->number . ' has no buyer phone.', 'data' => []];

        $body = $doc->typeLabel() . ' ' . $doc->number . ($doc->title ? (' — ' . $doc->title) : '')
            . "\nTotal: " . $doc->total_display . "\nView & accept: " . $doc->publicUrl();

        $res = $this->dispatchWhatsApp($wsId, $to, $body);
        if (! $res['ok']) return ['ok' => false, 'summary' => 'Could not send: ' . ($res['error'] ?: 'no WhatsApp engine connected.'), 'data' => []];

        if ($doc->status === \App\Models\SalesDoc::STATUS_DRAFT) $doc->status = \App\Models\SalesDoc::STATUS_SENT;
        if (! $doc->sent_at) $doc->sent_at = now();
        $doc->save();

        return ['ok' => true, 'summary' => 'Sent ' . $doc->typeLabel() . ' ' . $doc->number . ' to ' . self::maskPhone($to) . '.',
            'data' => ['number' => $doc->number, 'to' => self::maskPhone($to)], 'subject_type' => 'sales_doc', 'subject_id' => $doc->id];
    }

    private function resolveStage(int $wsId, int $pipelineId, string $name, bool $requireMatch): ?PipelineStage
    {
        $q = PipelineStage::where('workspace_id', $wsId)->where('pipeline_id', $pipelineId);
        if ($name !== '') {
            $match = (clone $q)->where('name', 'like', '%' . $name . '%')->orderBy('sort_order')->first();
            if ($match) return $match;
            if ($requireMatch) return null;
        }
        return $q->orderBy('sort_order')->first();
    }

    private static function maskPhone(string $digits): string
    {
        $len = strlen($digits);
        if ($len <= 4) return $digits;
        return str_repeat('*', max(0, $len - 4)) . substr($digits, -4);
    }
}

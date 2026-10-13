<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Services\ActivityLogger;
use App\Services\EmailTemplateService;
use App\Services\ReservationMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function __construct(
        private EmailTemplateService $templates,
        private ReservationMailer $mailer
    ) {
    }

    public function index(): JsonResponse
    {
        $templates = collect(array_keys(EmailTemplateService::DEFAULTS))
            ->map(fn($key) => EmailTemplate::forKey($key))
            ->values();

        $mailer = (string) config('mail.default');

        return response()->json([
            'templates' => $templates,
            'placeholders' => collect(EmailTemplateService::PLACEHOLDERS)
                ->map(fn($description, $key) => ['key' => $key, 'description' => $description])
                ->values(),
            'mail' => [
                'mailer' => $mailer,
                'from' => config('mail.from.address'),
                'delivers' => !in_array($mailer, ['log', 'array'], true),
            ],
        ]);
    }

    public function update(string $key, Request $request): JsonResponse
    {
        $template = EmailTemplate::forKey($key);
        $data = $this->validated($request, true);

        $template->update($data);

        ActivityLogger::log('email_template.updated', "E-mail sablon módosítva: {$key}", null, ['template' => $key], subjectLabel: $key);

        return response()->json($template->fresh());
    }

    public function reset(string $key): JsonResponse
    {
        $template = EmailTemplate::forKey($key);
        $defaults = EmailTemplateService::DEFAULTS[$key];

        $template->update([
            'name' => $defaults['name'],
            'description' => $defaults['description'],
            'subject' => $defaults['subject'],
            'body' => $defaults['body'],
        ]);

        return response()->json($template->fresh());
    }

    public function preview(string $key, Request $request): JsonResponse
    {
        EmailTemplate::forKey($key);
        $data = $this->validated($request, false);

        [$subject, $html] = $this->templates->compose($data['subject'], $data['body'], $this->templates->sampleVariables());

        return response()->json(['subject' => $subject, 'html' => $html]);
    }

    public function test(string $key, Request $request): JsonResponse
    {
        EmailTemplate::forKey($key);
        $data = $this->validated($request, false);
        $to = $request->validate(['to' => ['required', 'email', 'max:255']])['to'];

        try {
            $this->mailer->sendTest($data['subject'], $data['body'], $to);
        } catch (\Throwable $e) {
            ActivityLogger::log('email.failed', "Teszt e-mail küldése sikertelen: {$key} → {$to}", null, ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return response()->json(['message' => 'A teszt e-mail küldése sikertelen: ' . $e->getMessage()], 422);
        }

        ActivityLogger::log('email.sent', "Teszt e-mail elküldve: {$key} → {$to}", null, ['template' => $key, 'to' => $to]);

        return response()->json([
            'status' => 'sent',
            'to' => $to,
            'delivers' => !in_array((string) config('mail.default'), ['log', 'array'], true),
        ]);
    }

    /**
     * A sablon HTML törzsének tisztítása mentés előtt: aktív tartalom (script, iframe, űrlap stb.), eseménykezelő
     * attribútumok és javascript:/data: hivatkozások nem maradhatnak benne. A megengedett jelölés változatlan.
     */
    private function sanitizeBody(string $html): string
    {
        $dangerous = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea', 'select', 'meta', 'link', 'base', 'svg', 'math'];

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div id="tpl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($dangerous as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//@*') as $attr) {
            $name = strtolower($attr->nodeName);
            $value = strtolower(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($attr->nodeValue, ENT_QUOTES | ENT_HTML5)));

            $isUrl = in_array($name, ['href', 'src', 'xlink:href', 'action', 'formaction', 'background', 'poster'], true);
            if (str_starts_with($name, 'on') || ($isUrl && preg_match('/^(javascript|vbscript|data):/', $value))) {
                $attr->ownerElement?->removeAttributeNode($attr);
            }
        }

        $root = $doc->getElementById('tpl-root') ?? $xpath->query('//div[@id="tpl-root"]')->item(0);
        if (!$root) {
            return strip_tags($html);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        // A libxml a hivatkozásokban a {{változó}} jelölést is százalékkódolja: visszaállítjuk
        return str_replace(['%7B%7B', '%7D%7D'], ['{{', '}}'], $out);
    }

    private function validated(Request $request, bool $withActive): array
    {
        $rules = [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:30000'],
        ];
        if ($withActive) {
            $rules['is_active'] = ['required', 'boolean'];
        }

        $data = $request->validate($rules);
        $data['body'] = $this->sanitizeBody($data['body']);

        if ($this->templates->hasMalformedBraces($data['subject'], $data['body'])) {
            abort(response()->json([
                'message' => 'Hibás változó jelölés a sablonban. A változókat így kell megadni: {{valtozo}} (a kapcsos zárójelek nem lehetnek egymásba ágyazva vagy lezáratlanok).',
            ], 422));
        }

        $unknown = $this->templates->unknownPlaceholders($data['subject'], $data['body']);
        if ($unknown) {
            abort(response()->json([
                'message' => 'Ismeretlen változó a sablonban: ' . implode(', ', array_map(fn($n) => '{{' . $n . '}}', $unknown)),
            ], 422));
        }

        return $data;
    }
}

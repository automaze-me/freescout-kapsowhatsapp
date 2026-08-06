<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * Task 1 of Stage 3c: KapsoClient::listMessageTemplates() -- the Meta-proxy
 * call that lists a business account's message templates, filtered down to
 * the ones Stage 3c's send path can actually fill (approved, single
 * text-only BODY component, every other component parameter-free). See
 * "Stage 3c: template replies on a closed window" in
 * dev-notes/specs/2026-07-28-kapso-whatsapp-design.md for the eligibility
 * contract this test pins.
 */
class TemplateListTest extends TestCase
{
    protected $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The API key is a module-wide setting, not a per-account attribute
        // (see SendReplyTest / KapsoClientMetaTest).
        Settings::setApiKey('project-secret-key');
    }

    protected function fakeResponses(array $queue): void
    {
        $this->history = [];
        $stack         = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        KapsoClient::fakeHttp(new Client(['handler' => $stack]));
    }

    protected function account(): KapsoAccount
    {
        $account                     = new KapsoAccount();
        $account->phone_number_id    = '15550001111';
        $account->business_account_id = '999888777666555';

        return $account;
    }

    /**
     * Thin helper: fakes a single templates-list response carrying the given
     * `data` array and returns whatever listMessageTemplates() makes of it.
     * Mirrors the faking style every test above already uses inline
     * (fakeResponses() + a `data` envelope) rather than inventing a second
     * one.
     */
    protected function listWithTemplates(array $templates): array
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => $templates])),
        ]);

        return (new KapsoClient($this->account()))->listMessageTemplates();
    }

    public function test_the_list_is_fetched_from_the_business_account_endpoint()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                [
                    'name'       => 'order_shipped',
                    'language'   => 'en_US',
                    'status'     => 'APPROVED',
                    'category'   => 'UTILITY',
                    'components' => [
                        ['type' => 'BODY', 'text' => 'Your order shipped'],
                    ],
                ],
            ]])),
        ]);

        $templates = (new KapsoClient($this->account()))->listMessageTemplates();

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(
            'https://api.kapso.ai/meta/whatsapp/v24.0/999888777666555/message_templates',
            (string) $request->getUri()
        );
        $this->assertSame('project-secret-key', $request->getHeaderLine('X-API-Key'));

        $this->assertSame([
            'name'      => 'order_shipped',
            'language'  => 'en_US',
            'body'      => 'Your order shipped',
            'variables' => 0,
        ], $templates[0]);
    }

    /**
     * A: APPROVED, single text BODY with two placeholders -> offered,
     *    variables counted from the highest {{n}}.
     * B: PENDING -> filtered (not approved).
     * C: APPROVED, IMAGE header + BODY -> filtered (media header, the send
     *    path has no way to supply header media).
     * D: APPROVED, BODY + a URL button carrying a placeholder -> filtered
     *    (a dynamic button is a parameter the send path cannot fill).
     * E: APPROVED, BODY with no placeholders + a static FOOTER -> offered
     *    with variables === 0 (static, parameter-free HEADER/FOOTER text is
     *    fine -- only *parameterised* non-BODY components are excluded).
     */
    public function test_only_approved_text_body_templates_are_offered()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                [
                    'name'       => 'order_shipped',
                    'language'   => 'en_US',
                    'status'     => 'APPROVED',
                    'category'   => 'UTILITY',
                    'components' => [
                        ['type' => 'BODY', 'text' => 'Hello {{1}}, order {{2}} shipped'],
                    ],
                ],
                [
                    'name'       => 'pending_template',
                    'language'   => 'en_US',
                    'status'     => 'PENDING',
                    'category'   => 'UTILITY',
                    'components' => [
                        ['type' => 'BODY', 'text' => 'Still waiting on approval'],
                    ],
                ],
                [
                    'name'       => 'media_header_template',
                    'language'   => 'en_US',
                    'status'     => 'APPROVED',
                    'category'   => 'MARKETING',
                    'components' => [
                        ['type' => 'HEADER', 'format' => 'IMAGE'],
                        ['type' => 'BODY', 'text' => 'Check out our new stock'],
                    ],
                ],
                [
                    'name'       => 'dynamic_button_template',
                    'language'   => 'en_US',
                    'status'     => 'APPROVED',
                    'category'   => 'UTILITY',
                    'components' => [
                        ['type' => 'BODY', 'text' => 'Track your order'],
                        ['type' => 'BUTTONS', 'buttons' => [
                            ['type' => 'URL', 'text' => 'Track', 'url' => 'https://example.com/track/{{1}}'],
                        ]],
                    ],
                ],
                [
                    'name'       => 'static_footer_template',
                    'language'   => 'en_US',
                    'status'     => 'APPROVED',
                    'category'   => 'UTILITY',
                    'components' => [
                        ['type' => 'BODY', 'text' => 'Thanks for shopping with us'],
                        ['type' => 'FOOTER', 'text' => 'Reply STOP to unsubscribe'],
                    ],
                ],
            ]])),
        ]);

        $templates = (new KapsoClient($this->account()))->listMessageTemplates();

        $names = array_column($templates, 'name');
        $this->assertSame(['order_shipped', 'static_footer_template'], $names);

        $offered = array_combine($names, $templates);

        $this->assertSame('en_US', $offered['order_shipped']['language']);
        $this->assertSame('Hello {{1}}, order {{2}} shipped', $offered['order_shipped']['body']);
        $this->assertSame(2, $offered['order_shipped']['variables']);

        $this->assertSame('Thanks for shopping with us', $offered['static_footer_template']['body']);
        $this->assertSame(0, $offered['static_footer_template']['variables']);
    }

    /**
     * Meta also approves templates whose body uses *named* parameters
     * ({{customer_name}} instead of {{1}}). The send path only ever builds
     * positional parameters, and variable counting only sees {{n}} -- so a
     * named-parameter body would be offered as a zero-input template and
     * then rejected by Meta at send time. Eligibility must therefore
     * exclude any body whose placeholders are not all positional.
     */
    public function test_a_named_parameter_body_is_not_offered()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                [
                    'name'       => 'named_param_template',
                    'language'   => 'en_US',
                    'status'     => 'APPROVED',
                    'category'   => 'UTILITY',
                    'components' => [
                        ['type' => 'BODY', 'text' => 'Hi {{customer_name}}, your order is ready'],
                    ],
                ],
                [
                    'name'       => 'positional_template',
                    'language'   => 'en_US',
                    'status'     => 'APPROVED',
                    'category'   => 'UTILITY',
                    'components' => [
                        ['type' => 'BODY', 'text' => 'Hi {{1}}, your order is ready'],
                    ],
                ],
            ]])),
        ]);

        $templates = (new KapsoClient($this->account()))->listMessageTemplates();

        $this->assertSame(['positional_template'], array_column($templates, 'name'));
        $this->assertSame(1, $templates[0]['variables']);
    }

    public function test_a_malformed_list_response_yields_an_empty_list()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => 'nope'])),
            new Response(200, [], json_encode([])),
        ]);

        $client = new KapsoClient($this->account());

        $this->assertSame([], $client->listMessageTemplates());
        $this->assertSame([], $client->listMessageTemplates());
    }

    /**
     * Found live (2026-07-31): Kapso answers the template list with
     * 403 {"error": "Sandbox WhatsApp configurations only support messaging
     * endpoints"} for sandbox projects. The old blanket 401/403 mapping
     * rendered that as "Kapso rejected the API key" -- pointing the admin
     * at a key that was perfectly valid. A 403 WITH Kapso's own explanation
     * must surface that explanation; only a bare 403 may fall back to the
     * key message.
     */
    public function test_a_403_with_kapso_detail_surfaces_the_detail_not_the_key_message()
    {
        $this->fakeResponses([
            new Response(403, [], json_encode(['error' => 'Sandbox WhatsApp configurations only support messaging endpoints'])),
        ]);

        try {
            (new KapsoClient($this->account()))->listMessageTemplates();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(403, $e->getHttpStatus());
            $this->assertStringContainsString('Sandbox WhatsApp configurations', $e->getMessage());
            $this->assertStringNotContainsString('API key', $e->getMessage());
        }
    }

    public function test_an_api_error_surfaces_as_the_client_exception()
    {
        $this->fakeResponses([
            new Response(401, [], json_encode(['error' => 'Invalid API key'])),
        ]);

        try {
            (new KapsoClient($this->account()))->listMessageTemplates();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            // Reuses the existing key-rejected expectation pinned by
            // KapsoClientPlatformTest::test_a_401_demands_a_valid_api_key()
            // and KapsoClientMetaTest -- listMessageTemplates() goes
            // through the same apiRequest()/errorMessage() path as every
            // other call, so the 401 message must not be restated here.
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertStringContainsString('API key', $e->getMessage());
        }
    }

    public function test_authentication_category_templates_are_never_eligible()
    {
        // A copy-code auth template passes every structural check (text-only
        // positional body, OTP button with static text) -- only the category
        // identifies it. Meta requires a button OTP parameter this module's
        // body-only send path cannot supply, and auth templates cannot be
        // delivered to BSUIDs at all (error 131062), so the category is
        // excluded outright.
        $eligible = $this->listWithTemplates([[
            'name'       => 'auth_code',
            'status'     => 'APPROVED',
            'language'   => 'en_US',
            'category'   => 'AUTHENTICATION',
            'components' => [
                ['type' => 'BODY', 'text' => '{{1}} is your verification code.'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy code'],
                ]],
            ],
        ]]);

        $this->assertSame([], $eligible);
    }
}

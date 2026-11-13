<?php

declare(strict_types=1);

namespace MockPay\Http;

use MockPay\Domain\Payment;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds the customer-facing HTML responses of the test payment page.
 */
final class PaymentPageResponder
{
    private const STYLES = <<<'CSS'
        :root { color-scheme: light dark; --bg: #f5f5f4; --card: #fff; --text: #1c1917; --muted: #78716c;
            --line: #e7e5e4; --accent: #15803d; --danger: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #1c1917; --card: #292524; --text: #f5f5f4;
            --muted: #a8a29e; --line: #44403c; --accent: #22c55e; --danger: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 16px; background: var(--bg); color: var(--text);
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 560px; margin: 0 auto; background: var(--card); border: 1px solid var(--line);
            border-radius: 12px; padding: 24px; }
        h1 { margin: 0 0 4px; font-size: 22px; }
        .muted { color: var(--muted); font-size: 14px; margin: 0 0 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        td { padding: 8px 0; border-bottom: 1px solid var(--line); }
        td.amount { text-align: right; white-space: nowrap; padding-left: 12px; }
        tr.total td { font-weight: 600; border-bottom: 0; }
        form { display: flex; flex-wrap: wrap; gap: 8px; }
        button { flex: 1 1 140px; padding: 12px; font: inherit; border-radius: 8px; cursor: pointer;
            border: 1px solid var(--line); background: transparent; color: var(--text); }
        button.pay { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
        button.fail { color: var(--danger); }
        CSS;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * The order summary, with the pay/fail/cancel buttons while the payment can still be paid.
     */
    public function page(Payment $payment): ResponseInterface
    {
        $rows = '';
        foreach ($payment->orderRows() as $row) {
            $rows .= \sprintf(
                '<tr><td>%s × %s</td><td class="amount">%s</td></tr>',
                $row->quantity,
                self::escape($row->name),
                self::escape($this->money($row->totalAmount, $payment->currency())),
            );
        }

        $rows .= \sprintf(
            '<tr class="total"><td>Total</td><td class="amount">%s</td></tr>',
            self::escape($this->money($payment->orderTotal(), $payment->currency())),
        );

        if ($payment->status()->canAuthorize()) {
            $base = '/pay/' . rawurlencode($payment->id());
            $actions = \sprintf(
                '<form method="post" action="%1$s/pay">'
                . '<button class="pay" type="submit">Pay</button>'
                . '<button class="fail" type="submit" formaction="%1$s/fail">Fail payment</button>'
                . '<button type="submit" formaction="%1$s/cancel">Cancel</button>'
                . '</form>',
                self::escape($base),
            );
        } else {
            $actions = \sprintf(
                '<p>This payment is <strong>%s</strong> and can no longer be paid.</p>',
                self::escape($payment->status()->value),
            );
        }

        $reference = $payment->merchantReference();

        return $this->html(200, 'MockPay checkout', \sprintf(
            '<h1>MockPay checkout</h1><p class="muted">Test payment page. No real money moves. %s</p>'
            . '<table>%s</table>%s',
            $reference === null ? '' : 'Order ' . self::escape($reference) . '.',
            $rows,
            $actions,
        ));
    }

    public function message(int $status, string $title, string $text): ResponseInterface
    {
        return $this->html($status, $title, \sprintf(
            '<h1>%s</h1><p>%s</p>',
            self::escape($title),
            self::escape($text),
        ));
    }

    /**
     * 303 so the browser follows up with a GET whatever the original method was.
     */
    public function redirect(string $url): ResponseInterface
    {
        return $this->responseFactory->createResponse(303)->withHeader('Location', $url);
    }

    private function html(int $status, string $title, string $content): ResponseInterface
    {
        $document = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::escape($title) . '</title><style>' . self::STYLES . '</style></head>'
            . '<body><main>' . $content . '</main></body></html>';

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streamFactory->createStream($document));
    }

    /** Minor units to a two-decimal amount, e.g. 24900 SEK -> "249.00 SEK". */
    private function money(int $minorUnits, string $currency): string
    {
        return number_format($minorUnits / 100, 2, '.', ' ') . ' ' . $currency;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}

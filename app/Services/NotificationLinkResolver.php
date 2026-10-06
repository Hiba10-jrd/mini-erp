<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class NotificationLinkResolver
{
    public function resolve(array $data): ?string
    {
        $id = (int) ($data['entity_id'] ?? 0);
        if (($data['entity_type'] ?? null) === 'invoice' && Gate::allows('payments.view') && ($invoice = Invoice::find($id))) {
            return route('finance.receivables.index', ['search' => $invoice->number]);
        }
        if (($data['entity_type'] ?? null) === 'product' && Gate::allows('stock.access') && Product::find($id)) {
            return route('admin.stock.index', ['product' => $id]);
        }

        return null;
    }

    public function links(Collection $notifications): array
    {
        $data = $notifications->map(fn ($n) => $n->data);
        $invoices = Gate::allows('payments.view') ? Invoice::query()->whereIn('id', $data->where('entity_type', 'invoice')->pluck('entity_id'))->pluck('number', 'id') : collect();
        $products = Gate::allows('stock.access') ? Product::query()->whereIn('id', $data->where('entity_type', 'product')->pluck('entity_id'))->pluck('id')->flip() : collect();

        return $notifications->mapWithKeys(function ($notification) use ($invoices, $products): array {
            $item = $notification->data;
            $id = (int) ($item['entity_id'] ?? 0);
            $url = null;
            if (($item['entity_type'] ?? '') === 'invoice' && $invoices->has($id)) {
                $url = route('finance.receivables.index', ['search' => $invoices[$id]]);
            } elseif (($item['entity_type'] ?? '') === 'product' && $products->has($id)) {
                parse_str(parse_url($item['url'] ?? '', PHP_URL_QUERY) ?? '', $query);
                $parameters = ['product' => $id];
                if (isset($query['warehouse']) && ctype_digit((string) $query['warehouse'])) {
                    $parameters['warehouse'] = $query['warehouse'];
                }
                $url = route('admin.stock.index', $parameters);
            }

            return [$notification->id => $url];
        })->all();
    }
}

@extends('documents.layout')

@section('doc-title', 'QUOTATION')
@section('doc-number', $quotation->quotation_number)
@section('doc-date', 'Date: '.$quotation->quotation_date?->format('M d, Y').' · Valid until: '.$quotation->valid_until?->format('M d, Y'))

@section('content')
    <table class="grid">
        <tr>
            <td>
                <div class="label">Prepared for</div>
                <div class="box">
                    <strong>{{ $quotation->client_name }}</strong><br>
                    @if ($quotation->contact_person)Attn: {{ $quotation->contact_person }}<br>@endif
                    @if ($quotation->phone){{ $quotation->phone }}<br>@endif
                    @if ($quotation->email){{ $quotation->email }}<br>@endif
                    @if ($quotation->address)<span class="muted">{{ $quotation->address }}</span>@endif
                </div>
            </td>
            <td>
                <div class="label">Details</div>
                <div class="box">
                    Prepared by: {{ $quotation->preparedBy?->name ?? '—' }}<br>
                    Prepared on: {{ $quotation->quotation_date?->format('M d, Y') }}<br>
                    @if ($quotation->approvedBy)Approved by: {{ $quotation->approvedBy->name }}<br>@endif
                    Valid until: <strong>{{ $quotation->valid_until?->format('M d, Y') }}</strong>
                </div>
            </td>
        </tr>
    </table>

    @include('documents.partials.lines', [
        'lines' => $quotation->items->map(fn ($i) => ['sku' => $i->sku, 'name' => $i->product_name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'discount' => $i->discount, 'subtotal' => $i->subtotal])->all(),
        'totals' => $quotation->only(['subtotal', 'discount', 'tax_rate', 'tax_amount', 'grand_total']),
    ])

    @if ($quotation->terms_and_conditions)
        <div class="note"><strong>Terms and conditions</strong>
{{ $quotation->terms_and_conditions }}</div>
    @endif
@endsection

@section('legal')
    This quotation is an offer, valid until the date above. Prices and availability are confirmed when the customer accepts it and a Sales Order is issued.
@endsection

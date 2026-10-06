<x-dynamic-component
    :component="$getEntryWrapperView()"
    :entry="$entry"
>
    @inject('providers', 'PHPinnacle\Minos\Services\ProviderRegistry')
    @php($transactions = $entry->getTransactions())
    @php($isContained = $entry->isContained())

    <div {{ $getExtraAttributeBag()->class(['space-y-4']) }}>
        @forelse ($transactions->take($entry->getLimit()) as $transaction)
            <article @class([
                'overflow-hidden',
                'rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $isContained,
                'border-t border-gray-200 dark:border-white/10' => ! $isContained && ! $loop->first,
            ])>
                <div @class([
                    'flex flex-wrap items-start justify-between gap-4',
                    'p-5' => $isContained,
                    'py-3' => ! $isContained,
                ])>
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                            <x-filament::icon :icon="$transaction->type->getIcon()" class="size-5" />
                        </span>
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-950 dark:text-white">{{ $transaction->method->name }}</div>
                            <div class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                {{ $transaction->type->getLabel() }} · {{ $transaction->created_at->translatedFormat('j M Y, H:i') }}
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 sm:justify-end">
                        <span class="font-mono text-base font-semibold tabular-nums text-gray-950 dark:text-white">{{ $transaction->amount->format() }}</span>
                        <x-filament::badge :color="$transaction->status->getColor()">
                            {{ $transaction->status->getLabel() }}
                        </x-filament::badge>
                    </div>
                </div>

                @if ($transaction->status === \PHPinnacle\Minos\Enums\TransactionStatus::Success)
                    <div @class([
                        'flex flex-wrap gap-x-6 gap-y-1 border-t border-gray-100 py-3 text-sm dark:border-white/10',
                        'px-5' => $isContained,
                    ])>
                        @if ($transaction->type === \PHPinnacle\Minos\Enums\TransactionType::AUTHORIZE)
                            <span class="text-gray-600 dark:text-gray-300">{{ __('phpinnacle-minos::resources.transaction.fields.captured') }} <strong class="font-semibold text-gray-950 dark:text-white">{{ $transaction->captured()->format() }}</strong></span>
                            <span class="text-gray-600 dark:text-gray-300">{{ __('phpinnacle-minos::resources.transaction.history.capturable') }} <strong class="font-semibold text-gray-950 dark:text-white">{{ $transaction->capturable()->format() }}</strong></span>
                        @else
                            <span class="text-gray-600 dark:text-gray-300">{{ __('phpinnacle-minos::resources.transaction.history.received') }} <strong class="font-semibold text-gray-950 dark:text-white">{{ $transaction->received()->format() }}</strong></span>
                            <span class="text-gray-600 dark:text-gray-300">{{ __('phpinnacle-minos::resources.transaction.fields.refunded') }} <strong class="font-semibold text-gray-950 dark:text-white">{{ $transaction->refunded()->format() }}</strong></span>
                        @endif
                    </div>
                @endif

                @php($operations = $entry->getOperations($transaction))

                @if ($operations->isNotEmpty())
                    <ol @class([
                        'space-y-3 border-t border-gray-100 py-4 dark:border-white/10',
                        'px-5' => $isContained,
                    ])>
                        @foreach ($operations as $operation)
                            <li class="grid grid-cols-[0.75rem_minmax(0,1fr)] gap-3">
                                <span class="mt-2 size-2 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                                <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                    <div class="min-w-0">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $operation->type->getLabel() }}</span>
                                        <span class="text-sm text-gray-500 dark:text-gray-400">· {{ $operation->created_at->translatedFormat('j M Y, H:i') }}</span>
                                        @if ($operation->reason)
                                            <div class="text-sm text-gray-600 dark:text-gray-300">{{ $operation->reason }}</div>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="font-mono text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ $operation->amount->format() }}</span>
                                        <x-filament::badge :color="$operation->status->getColor()">
                                            {{ $operation->status->getLabel() }}
                                        </x-filament::badge>
                                    </div>
                                    @if ($entry->canOperate($operation, 'refund', $providers))
                                        <div class="w-full">{{ ($entry->getAction('refund'))(['transaction' => $operation->id]) }}</div>
                                    @endif
                                    @if ($entry->canOperate($operation, 'cancel', $providers))
                                        <div class="w-full">{{ ($entry->getAction('cancel'))(['transaction' => $operation->id]) }}</div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @php($transactionUrl = $entry->getTransactionUrl($transaction))
                <div @class([
                    'flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-gray-100 py-3 dark:border-white/10',
                    'px-5' => $isContained,
                ])>
                    @if ($transactionUrl)
                        <a href="{{ $transactionUrl }}" class="text-sm font-medium text-primary-600 hover:underline focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:text-primary-400">
                            {{ __('phpinnacle-minos::resources.transaction.history.view') }}
                        </a>
                    @endif
                    @if ($entry->canOperate($transaction, 'capture', $providers))
                        {{ ($entry->getAction('capture'))(['transaction' => $transaction->id]) }}
                    @endif
                    @if ($entry->canOperate($transaction, 'void', $providers))
                        {{ ($entry->getAction('void'))(['transaction' => $transaction->id]) }}
                    @endif
                    @if ($entry->canOperate($transaction, 'refund', $providers))
                        {{ ($entry->getAction('refund'))(['transaction' => $transaction->id]) }}
                    @endif
                    @if ($entry->canOperate($transaction, 'cancel', $providers))
                        {{ ($entry->getAction('cancel'))(['transaction' => $transaction->id]) }}
                    @endif
                </div>
            </article>
        @empty
            <div @class([
                'py-8 text-center',
                'rounded-xl border border-dashed border-gray-300 px-5 dark:border-white/20' => $isContained,
            ])>
                <div class="font-medium text-gray-900 dark:text-gray-100">{{ __('phpinnacle-minos::resources.transaction.history.empty_heading') }}</div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('phpinnacle-minos::resources.transaction.history.empty_description') }}</p>
            </div>
        @endforelse

        @if ($transactions->count() > $entry->getLimit())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('phpinnacle-minos::resources.transaction.history.latest', ['count' => $entry->getLimit()]) }}</p>
        @endif
    </div>
</x-dynamic-component>

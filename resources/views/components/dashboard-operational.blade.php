    <header class="dashboard-header"><div><p class="dashboard-greeting">{{ __('Bonjour, :name', ['name' => auth()->user()->name]) }}</p><span class="dashboard-eyebrow">{{ __('Tableau de bord') }}</span><h1>{{ __('Vue d’ensemble') }}</h1><p>{{ __('Synthèse de votre activité.') }}</p></div></header>
    @if (count($cards) === 0)
        <section class="dashboard-panel"><h2>{{ __('Bienvenue dans votre ERP') }}</h2><p class="dashboard-note">{{ __('Accédez à vos modules depuis la navigation.') }}</p></section>
    @else
        <div class="dashboard-kpis" style="--dashboard-columns: {{ min(4, count($cards)) }}">
            @foreach ($cards as $card)
                <article class="dashboard-kpi dashboard-tone-{{ $card['color'] }}">
                    <div class="dashboard-kpi-heading"><h2>{{ __($card['label']) }}</h2><span class="dashboard-icon" aria-hidden="true"><x-premium-icon name="document" /></span></div>
                    <strong><bdi>{{ $card['value'] }}</bdi></strong><p>{{ __('Situation actuelle, toutes périodes') }}</p>
                </article>
            @endforeach
        </div>
        @if (count($situation))
            <section class="dashboard-panel dashboard-situation">
                <div class="dashboard-panel-heading"><div><h2>{{ __('Situation actuelle') }}</h2><p>{{ __('Indépendante de la période sélectionnée') }}</p></div><span class="dashboard-live-dot" aria-hidden="true"></span></div>
                @foreach ($situation as $label => $value)<div class="dashboard-situation-row"><span>{{ __($label) }}</span><strong><bdi>{{ $value }}</bdi></strong></div>@endforeach
            </section>
        @endif
        @if (count($pending))
            <section class="dashboard-panel dashboard-pending">
                <div class="dashboard-panel-heading"><div><h2>{{ __('Opérations en attente') }}</h2><p>{{ __('À suivre aujourd’hui, toutes périodes confondues') }}</p></div></div>
                <div class="dashboard-pending-grid" style="--dashboard-columns: {{ min(6, count($pending)) }}">
                    @foreach ($pending as $item)<div class="dashboard-pending-item"><strong><bdi>{{ $item['value'] }}</bdi></strong><a href="{{ route($item['route']) }}" wire:navigate>{{ __($item['label']) }}</a></div>@endforeach
                </div>
            </section>
        @endif
        <div class="dashboard-bottom-grid {{ count($alerts) === 0 ? 'dashboard-single-panel' : '' }}">
            <section class="dashboard-panel dashboard-activity">
                <div class="dashboard-panel-heading"><div><h2>{{ __('Activité récente') }}</h2><p>{{ __('Dernières opérations et contacts enregistrés') }}</p></div><span class="dashboard-unit">{{ $feed->count() }}</span></div>
                <ul class="dashboard-feed">
                    @forelse ($feed as $item)
                        <li wire:key="operational-{{ $item['key'] }}"><span class="dashboard-feed-icon dashboard-tone-blue" aria-hidden="true"><x-premium-icon name="document" /></span>
                            <div class="dashboard-feed-body"><span class="dashboard-feed-label">{{ __($item['label']) }}</span>
                                @if ($item['url'])<a href="{{ $item['url'] }}" wire:navigate>{{ $item['reference'] ?? __('Sans référence') }}</a>@else<strong>{{ $item['reference'] ?? __('Sans référence') }}</strong>@endif
                                @if ($item['detail'])<p>{{ $item['detail'] }}</p>@endif
                                <p><time datetime="{{ $item['date']?->toDateString() }}">{{ $item['date']?->format('d/m/Y') }}</time></p>
                            </div>
                            @if ($item['amount'] !== null)<bdi class="dashboard-feed-amount">{{ \App\Services\ReportService::money($item['amount'], $currency) }}</bdi>@endif
                        </li>
                    @empty<li class="dashboard-empty">{{ __('Aucune opération.') }}</li>@endforelse
                </ul>
            </section>
            @if (count($alerts))
                <section class="dashboard-panel dashboard-alerts">
                    <div class="dashboard-panel-heading"><div><h2>{{ __('Alertes à traiter') }}</h2><p>{{ __('Priorités et documents à valider') }}</p></div><span class="dashboard-alert-symbol" aria-hidden="true">!</span></div>
                    <div class="dashboard-alert-list">
                        @foreach ($alerts as $label => $rows)
                            @if ($rows->isNotEmpty())<div class="dashboard-alert-group dashboard-tone-orange"><h3>{{ __($label) }}</h3>@foreach ($rows as $row)<p>{{ $row }}</p>@endforeach</div>@endif
                        @endforeach
                        @if (collect($alerts)->every(fn ($rows) => $rows->isEmpty()))<p class="dashboard-empty">{{ __('Aucune alerte à traiter.') }}</p>@endif
                    </div>
                </section>
            @endif
        </div>
    @endif
    <div wire:loading class="dashboard-loading" role="status">{{ __('Actualisation en cours…') }}</div>

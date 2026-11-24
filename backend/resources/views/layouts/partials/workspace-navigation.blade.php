{{-- Satu pohon menu untuk sidebar desktop dan drawer mobile. Dashboard adalah shortcut utama, tanpa folder Beranda. --}}
@foreach (\App\Support\AdminWorkspaceMenu::groups() as $group)
    @if ($group['key'] === 'utama')
        <div class="pln-home-shortcut mb-2 rounded-md border border-blue-200 bg-white/90 p-1" data-pln-home-shortcut>
            <ul class="space-y-1">
                @foreach ($group['nodes'] as $node)
                    @if (isset($node['item']) && $node['item'])
                        <li>@include('layouts.partials.sidebar-link', ['item' => $node['item']])</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @else
        @php
            $groupItems = collect($group['nodes'])->flatMap(function ($node) {
                return isset($node['item']) ? [$node['item']] : collect($node['children'])->pluck('item')->all();
            });
            $groupActive = $groupItems->contains(fn ($item) => is_array($item) && request()->routeIs(...(array) $item['active']));
        @endphp
        <details class="pln-tree-group" data-pln-tree="{{ $group['key'] }}" @if ($groupActive || $group['default_open']) open @endif>
            <summary class="pln-tree-summary sidebar-section-label" aria-label="Buka atau tutup {{ $group['label'] }}">
                <span aria-hidden="true" class="pln-tree-folder">▣</span>
                <span class="sidebar-label">{{ $group['label'] }}</span>
                <span class="pln-tree-arrow sidebar-label" aria-hidden="true">▸</span>
            </summary>
            <ul class="pln-tree-list">
                @foreach ($group['nodes'] as $node)
                    @if (isset($node['item']))
                        @if ($node['item'])
                            <li>@include('layouts.partials.sidebar-link', ['item' => $node['item']])</li>
                        @endif
                    @else
                        @php
                            $subActive = collect($node['children'])->contains(fn ($child) => request()->routeIs(...(array) $child['item']['active']));
                        @endphp
                        <li>
                            <details class="pln-tree-subgroup" @if ($subActive) open @endif>
                                <summary class="pln-tree-subsummary">
                                    <span aria-hidden="true">▹</span>
                                    <span class="sidebar-label">{{ $node['label'] }}</span>
                                </summary>
                                <ul class="pln-tree-sublist">
                                    @foreach ($node['children'] as $child)
                                        <li>@include('layouts.partials.sidebar-link', ['item' => $child['item']])</li>
                                    @endforeach
                                </ul>
                            </details>
                        </li>
                    @endif
                @endforeach
            </ul>
        </details>
    @endif
@endforeach

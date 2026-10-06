@props(['status' => 'neutral', 'label' => null])
@php
    $labels = ['created'=>'Création','updated'=>'Modification','deleted'=>'Suppression','issued'=>'Émis','validated'=>'Validé','paid'=>'Payé','active'=>'Actif','draft'=>'Brouillon','cancelled'=>'Annulé','overdue'=>'En retard','partial'=>'Partiellement payé','confirmed'=>'Confirmé','info'=>'Information','warning'=>'Attention'];
    $tone = match($status) {
        'created','validated','paid','active','confirmed','success' => 'success',
        'partial','partially_paid','upcoming','warning' => 'warning',
        'deleted','cancelled','overdue','error','danger' => 'danger',
        'issued','info','updated' => 'info', default => 'neutral',
    };
@endphp
<span {{ $attributes->class(['erp-badge', 'erp-badge-'.$tone]) }}>{{ $label ?? $labels[$status] ?? $status }}</span>

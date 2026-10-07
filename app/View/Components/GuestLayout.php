<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    /**
     * @param  string  $theme  'gold' (bawaan) atau 'terracotta' — palet logo baru, sementara baru dipakai halaman login.
     */
    public function __construct(public string $theme = 'gold') {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}

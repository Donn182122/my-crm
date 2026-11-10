<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        return view('foundation', ['title' => 'My CRM foundation']);
    }

    public function admin(): string
    {
        return view('foundation', ['title' => 'Administrator access verified']);
    }
}

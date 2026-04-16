<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StripeAuthController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function create(Request $request) {
        $stripe = new \Stripe\StripeClient(env('SECRET_KEY'));

        $respStripe = $stripe->accounts->create([
            'country' => 'US',
            'email' => 'rampukar.dev@gmail.com',
            'controller' => [
                'fees' => ['payer' => 'application'],
                'losses' => ['payments' => 'application'],
                'stripe_dashboard' => ['type' => 'express'],
            ],
        ]);
        
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $respStripe,
        ]);
    }

    public function retrieveAccount(Request $request) {

        $stripe = new \Stripe\StripeClient(env('SECRET_KEY'));
        $respAccount = $stripe->respAccounts->retrieve('acct_1Nv0FGQ9RKHgCVdK', []);
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $respAccount,
        ]);
    }
}

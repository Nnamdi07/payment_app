<?php

namespace App\Http\Controllers;

use App\Mail\PaymentCompleted;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Exception;
use Illuminate\Support\Facades\Auth;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PaymentServiceController extends Controller
{
    /**
     * Initialize Rave payment process
     * @return void
     */
    protected $secretKey;
    protected $apiKey;
    private $publicKey;
    private $paystackEndpoint = 'https://api.paystack.co';
    private $flutterwaveEndpoint = 'https://api.flutterwave.com/v3/payments';

    function __construct()
    {
        // $this->secretKey = config('flutterwave.secretKey');
        $this->secretKey = env('FLW_SECRET_KEY');
        $this->publicKey = env('FLW_PUBLIC_KEY');
        $this->apiKey = env('PAYSTACK_SECRET_KEY');
    }

    public function initialize($user, $amount, $gateway = "paystack"){

        // request()['gateway'] = "paystack";

        $user = Auth::user();
        $transaction = new Transaction();

        if (request()->gateway == "flutterwave") {

            $reference = uniqid();

            $transaction->reference = $reference;
            $transaction->amount = $amount;
            $transaction->payment_gateway = "flutterwave";
            $transaction->status = "pending";
            $transaction->user_id = $user->id;

            // Enter the details of the payments
            $payload = [
                'payment_options' => 'card,banktransfer',
                'amount' => $amount * 100,
                'email' => $user->email,
                'tx_ref' => $reference,
                'currency' => "NGN",
                'redirect_url' => route('callback'),
                'customer' => [
                    'email' => $user->email,
                    "phone_number" => $user->phone_number,
                    "name" => $user->name,
                ],
    
                "customizations" => [
                    "title" => 'JAMB Passcode',
                    "description" => "2nd November"
                ]
            ];


            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->secretKey}"
            ])->post("{$this->flutterwaveEndpoint}", $payload);



            if (!$response->ok()) {
                throw new Exception($response->json()['message']);
            }
            $transaction->save();

            $data = $response->json()['data'];
            return redirect($data['link']);
        }

        if (request()->gateway == "paystack") {
            $reference = uniqid();


            $transaction->reference = $reference;
            $transaction->amount = $amount;
            $transaction->payment_gateway = "paystack";
            $transaction->status = "pending";
            $transaction->user_id = $user->id;

            $amountInKobo = ceil($amount * 100);

            $metadata = [
                "title" => "JAMB Passcode",
                "description" => "2nd November",
            ];


            $payload = [
                'amount' => $amountInKobo,
                'email' => $user->email,
                'reference' => $reference,
                'first_name' => $user->name,
                'metadata' => json_encode($metadata),
                'callback_url' => route('callback', $transaction->uuid),
            ];

            $transaction->save();


            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}"
            ])->post("{$this->paystackEndpoint}/transaction/initialize", $payload);

            if (!$response->ok()) {
                $message = $response->json()['message'];
                if (Str::contains($message, "Amount cannot be processed")) {
                    throw new Exception("Amount too large to be processed using this payment method");
                };

                if (!$message) {
                    throw new Exception("Unknown exception");
                }

                throw new Exception($message);
            }

            $data = $response->json()['data'];
            return ($data['authorization_url']);
        }
    }
    
}

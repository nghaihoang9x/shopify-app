<?php

use App\Exceptions\ShopifyProductCreatorException;
use App\Lib\AuthRedirection;
use App\Lib\EnsureBilling;
use App\Lib\ProductCreator;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Shopify\Auth\OAuth;
use Shopify\Auth\Session as AuthSession;
use Shopify\Clients\HttpHeaders;
use Shopify\Clients\Rest;
use Shopify\Context;
use Shopify\Exception\InvalidWebhookException;
use Shopify\Utils;
use Shopify\Webhooks\Registry;
use Shopify\Webhooks\Topics;

// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Shopify\Rest\Admin2022_10\ScriptTag;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::fallback(function (Request $request) {

    $shop_domain = $request->get('shop');
    $session = Session::where('shop', $shop_domain)->get(['access_token','active_id'])->first();
    $access_token = data_get($session, 'access_token', '');
    $active_user_id = data_get($session, 'active_id', '');
    $client = new Rest(
        $shop_domain,
        $access_token // shpat_***
    );
    $res = $client->get('shop')->getDecodedBody();

    if(!empty(data_get($res,'shop',null))) $shop = data_get($res,'shop',null);

    $url = env(   "API_URL") . '/v1/auth/token/entry';

    $data = [
        "partner" => "shopify.com",
        "email" => data_get($shop,"email",null),
        "phone" => data_get($shop,"phone",null),
        "displayName" => data_get($shop,"shop_owner",null),
        "photoURL" => "",
        "language" => data_get($shop,"country",null),
        "uid" => $active_user_id,
        "customClaims" => [
            "info" => $shop
        ],
        "access_token" => $access_token
    ];

    $response = Http::withHeaders([
        'X-First' => 'foo',
        'X-Second' => 'bar'
    ])->post($url,$data);

    if($response->status() == 200) {
        $data = data_get($response,"data", []);
        $user_ui = data_get($data,"uid","");
        $token = data_get($data,"idToken","");
        $refreshToken = data_get($data,"refreshToken","");
        $expiresIn = data_get($data,"expiresIn","");
        $redirectUrl = data_get($data,"redirectUrl","") . '?idToken=' . $token . '&expiresIn=' . $expiresIn . '&refreshToken=' . $refreshToken;
        // dd($response->json());
        Session::where('access_token', $access_token)->update(['active_id' => $user_ui]);

        return redirect()->away($redirectUrl);
    }else{
        $request->headers->set('X-Shopify-Access-Token' , $access_token);
        $request->headers->set('Content-Type' , 'application/json');
//dd($shop_domain . '/admin/api/2022-10/script_tags.json');
//        $test_session = Utils::loadCurrentSession(
//            $request->header(),
//            $request->cookie(),
//            false
//        );
//        dd($test_session);
//        dd($client->get('shop'));
//        $shop_domain = 'dev-nghaihoang.myshopify.com';
//        $session = Session::where('shop', $shop_domain)->get(['access_token','active_id'])->first();
//dd($session);
//        $script_tag = new ScriptTag(
//            $shop_domain,
//            $access_token
//        );
//        dd($script_tag);
//        dd($script_tag);
//        dd($response->json());
    }

})->middleware('shopify.installed');


Route::get('/api/auth', function (Request $request) {
    $shop = Utils::sanitizeShopDomain($request->query('shop'));

    // Delete any previously created OAuth sessions that were not completed (don't have an access token)
    Session::where('shop', $shop)->where('access_token', null)->delete();

    return AuthRedirection::redirect($request);
});

Route::get('/api/auth/callback', function (Request $request) {
    $session = OAuth::callback(
        $request->cookie(),
        $request->query(),
        ['App\Lib\CookieHandler', 'saveShopifyCookie'],
    );

    $host = $request->query('host');
    $shop = Utils::sanitizeShopDomain($request->query('shop'));

    $response = Registry::register('/api/webhooks', Topics::APP_UNINSTALLED, $shop, $session->getAccessToken());
    if ($response->isSuccess()) {
        Log::debug("Registered APP_UNINSTALLED webhook for shop $shop");
    } else {
        Log::error(
            "Failed to register APP_UNINSTALLED webhook for shop $shop with response body: " .
                print_r($response->getBody(), true)
        );
    }

    $redirectUrl = Utils::getEmbeddedAppUrl($host);
    if (Config::get('shopify.billing.required')) {
        list($hasPayment, $confirmationUrl) = EnsureBilling::check($session, Config::get('shopify.billing'));

        if (!$hasPayment) {
            $redirectUrl = $confirmationUrl;
        }
    }

    return redirect($redirectUrl);
});

Route::get('/api/products/count', function (Request $request) {
//    dd($request->header());
    $shop_domain = 'dev-nghaihoang.myshopify.com';
    $session = Session::where('shop', $shop_domain)->get(['access_token','active_id'])->first();

    $script_tag = new ScriptTag($session);
//    dd($script_tag);
});

Route::post('/update/script/tag',function (Request $request) {
    $shop_domain = $request->post('shop_domain');
    $script_url = $request->post('script_url');
    $session = Session::where('shop', $shop_domain)->get(['access_token','active_id'])->first();
    $access_token = data_get($session, 'access_token', '');
    $active_user_id = data_get($session, 'active_id', '');

    $data = [
        "script_tag" => [
            "event" => "onload",
            "src" => "https://example.com/my_script2.js"
        ]
    ];
    $response = Http::withHeaders([
        'X-Shopify-Access-Token' => $access_token,
        'Content-Type' => 'application/json'
    ])->post('https://' . $shop_domain . '/admin/api/2022-10/script_tags.json',$data);
//        dd($shop_domain . '/admin/api/2022-10/script_tags.json',json_encode($data));
    dd(json_decode($response->body()));
});

Route::get('/api/products/create', function (Request $request) {
    /** @var AuthSession */
    $session = $request->get('shopifySession'); // Provided by the shopify.auth middleware, guaranteed to be active

    $success = $code = $error = null;
    try {
        ProductCreator::call($session, 5);
        $success = true;
        $code = 200;
        $error = null;
    } catch (\Exception $e) {
        $success = false;

        if ($e instanceof ShopifyProductCreatorException) {
            $code = $e->response->getStatusCode();
            $error = $e->response->getDecodedBody();
            if (array_key_exists("errors", $error)) {
                $error = $error["errors"];
            }
        } else {
            $code = 500;
            $error = $e->getMessage();
        }

        Log::error("Failed to create products: $error");
    } finally {
        return response()->json(["success" => $success, "error" => $error], $code);
    }
})->middleware('shopify.auth');

Route::post('/api/webhooks', function (Request $request) {
    try {
        $topic = $request->header(HttpHeaders::X_SHOPIFY_TOPIC, '');

        $response = Registry::process($request->header(), $request->getContent());
        if (!$response->isSuccess()) {
            Log::error("Failed to process '$topic' webhook: {$response->getErrorMessage()}");
            return response()->json(['message' => "Failed to process '$topic' webhook"], 500);
        }
    } catch (InvalidWebhookException $e) {
        Log::error("Got invalid webhook request for topic '$topic': {$e->getMessage()}");
        return response()->json(['message' => "Got invalid webhook request for topic '$topic'"], 401);
    } catch (\Exception $e) {
        Log::error("Got an exception when handling '$topic' webhook: {$e->getMessage()}");
        return response()->json(['message' => "Got an exception when handling '$topic' webhook"], 500);
    }
});

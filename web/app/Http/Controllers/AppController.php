<?php

namespace App\Http\Controllers;

class AppController extends Controller
{
    public function callback(Request $request)
    {
        if(!empty($request->has('shop'))) {
            return file_get_contents(base_path('frontend/index.html'));    
        }
        $shop_domain = $request->get('shop');
        $session = Session::where('shop', $shop_domain)->get(['access_token','active_id'])->first();
        $access_token = data_get($session, 'access_token', '');
        $active_user_id = data_get($session, 'active_id', '');

        $client = new Rest(
            $shop_domain,
            $access_token
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
            Session::where('access_token', $access_token)->update(['active_id' => $user_ui]);
    
            return redirect()->away($redirectUrl);
        }else{
            $request->headers->set('X-Shopify-Access-Token' , $access_token);
            $request->headers->set('Content-Type' , 'application/json');
        }
    }

}

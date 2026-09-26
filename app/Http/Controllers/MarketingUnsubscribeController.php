<?php
namespace App\Http\Controllers;
use App\Libraries\MultiDB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
class MarketingUnsubscribeController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate(['token'=>'required|string|max:4000']);
        try { $data = json_decode(Crypt::decryptString($request->input('token')),true,512,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { abort(403); }
        abort_unless(isset($data['company_id'],$data['email'],$data['locale']) && filter_var($data['email'],FILTER_VALIDATE_EMAIL),403);
        if (config('ninja.db.multi_db_enabled')) {
            abort_unless(in_array($data['db'] ?? '',MultiDB::$dbs,true),403);
            MultiDB::setDb($data['db']);
        }
        $labels = json_decode(file_get_contents(resource_path('marketing/labels.json')),true)[$data['locale']] ?? [];
        if ($request->isMethod('post')) {
            DB::table('marketing_suppressions')->updateOrInsert(['company_id'=>$data['company_id'],'email'=>$data['email']],['created_at'=>now()]);
            return response(e($labels['unsubscribe_recorded'] ?? 'Preference saved.'))->header('Cache-Control','no-store');
        }
        return response('<!doctype html><html lang="'.e($data['locale']).'"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($labels['unsubscribe']).'</title><body><form method="post">'.csrf_field().'<input type="hidden" name="token" value="'.e($request->input('token')).'"><p>'.e($data['email']).'</p><button type="submit">'.e($labels['unsubscribe']).'</button></form></body></html>')->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer');
    }
}

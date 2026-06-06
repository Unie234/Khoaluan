<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $r) {
        $items=DB::table('notifications')->where('user_id',$r->user_id)
            ->whereNull('deleted_at')->orderByDesc('created_at')
            ->limit(min($r->integer('limit',20),100))->get();
        return response()->json(['status'=>'success','notifications'=>$items]);
    }

    public function read(Request $r) {
        DB::table('notifications')->where('id',$r->notification_id)
            ->where('user_id',$r->user_id)->update(['is_read'=>1]);
        return response()->json(['status'=>'success']);
    }

    public function destroy(Request $r) {
        DB::table('notifications')->where('id',$r->notification_id)
            ->where('user_id',$r->user_id)->update(['deleted_at'=>now()]);
        return response()->json(['status'=>'success']);
    }
}
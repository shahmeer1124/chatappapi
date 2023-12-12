<?php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;
use Kreait\Firebase\Messaging\CloudMessage;




use App\Http\Controllers\Controller;


class LoginController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'avatar' => 'required',
            'name' => 'required',
            'type' => 'required',
            'open_id' => 'required',
            'email' => 'max:50',
            'phone' => 'max:30',

        ]);

        if ($validator->fails()) {
            return ['code' => -1, 'data' => 'no valid data ', 'msg' => $validator->errors()->first()];
        }

        try {
            $validated = $validator->validated();
            $map = [];
            $map['type'] = $validated['type'];
            $map['open_id'] = $validated['open_id'];
            $result = DB::table('users')->select('avatar', 'name', 'description', 'type', 'token', 'access_token', 'online')
                ->where($map)->first();

            if (empty($result)) {
                // User doesn't exist, create a new one
                $validated['token'] = md5(uniqid() . rand(10000, 99999));
                $validated['created_at'] = Carbon::now();
                $validated['access_token'] = md5(uniqid() . rand(1000000, 9999999));
                $validated['expire_date'] = Carbon::now()->addDays(30);
                $user_id = DB::table('users')->insertGetId($validated);
                $user_result = DB::table('users')->select('avatar', 'name', 'description', 'type', 'token', 'access_token', 'online')
                    ->where('id', '=', $user_id)->first();
                return ['code' => 0, 'data' => $user_result, 'msg' => 'User has been created'];
            } else {
                // User exists, update access token
                $access_token = md5(uniqid() . rand(1000000, 9999999));
                $expire_date = Carbon::now()->addDays(30);


                // Update the access token and expire date in the database
                DB::table("users")->where($map)->update([
                    "access_token" => $access_token,
                    "expire_date" => $expire_date,

                ]);

                // Update the access_token in the result object
                $result->access_token = $access_token;

                return ['code' => 0, 'data' => $result, 'msg' => 'User information updated'];
            }
        } catch (Exception $e) {
            return ['code' => -1, 'data' => 'no data available', 'msg' => (string) $e];
        }
    }


    public function contact(Request $request)
    {
        $token = $request->user_token;
        $res = DB::table('users')->select(
            'avatar',
            'description',
            'online',
            'token',
            'name',
            'fcmtoken'
        )->where('token', '!=', $token)->get();
        return ['code' => -1, 'data' => $res, 'msg' => 'got all the users info'];


    }

    public function send_notic(Request $request)
    {
        $user_token = $request->user_token;
        $user_avatar = $request->user_avatar;
        $user_name = $request->user_name;
        $to_token = $request->input("to_token");

        $to_avatar = $request->input("to_avatar");
        $to_name = $request->input("to_name");
        $call_type = $request->input("call_type");
        $doc_id = $request->input("doc_id");
        if (empty($doc_id)) {
            $doc_id = "";
        }
        $res = DB::table("users")->select("avatar", "name", "token", "fcmtoken")->where("token", "=", $to_token)->first();
        if (empty($res)) {
            return ['code' => -1, 'data' => " ", 'msg' => 'user does not exist'];

        }
        $device_token = $res->fcmtoken;

        try {
            if (!empty($device_token)) {
                $messaging = app("firebase.messaging");
                if ($call_type == "cancel") {
                    $message = CloudMessage::fromArray([
                        'token' => $device_token,
                        'data' => [
                            'token' => $user_token,
                            'avatar' => $user_avatar,
                            'name' => $user_name,
                            'doc_id' => $doc_id,
                            'call_type' => $call_type
                        ]
                    ]);
                    $messaging->send($message);
                } else if ($call_type == "voice") {
                    $message = CloudMessage::fromArray([
                        'token' => $device_token,
                        'data' => [
                            'token' => $user_token,
                            'avatar' => $user_avatar,
                            'name' => $user_name,
                            'doc_id' => $doc_id,
                            'call_type' => $call_type
                        ],
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'channel_id' => 'xxxx',
                                'title' => 'Voice call made by' . $user_name,
                                'body' => 'please click to answer the voice call'
                            ]
                        ]
                    ]);
                }
                else if ($call_type == "video") {
                    $message = CloudMessage::fromArray([
                        'token' => $device_token,
                        'data' => [
                            'token' => $user_token,
                            'avatar' => $user_avatar,
                            'name' => $user_name,
                            'doc_id' => $doc_id,
                            'call_type' => $call_type
                        ],
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'channel_id' => 'xxxx',
                                'title' => 'Video call made by' . $user_name,
                                'body' => 'please click to answer the video call'
                            ]
                        ]
                    ]);
                }
                else if ($call_type == "message") {
                    $message = CloudMessage::fromArray([
                        'token' => $device_token,
                        'data' => [
                            'token' => $user_token,
                            'avatar' => $user_avatar,
                            'name' => $user_name,
                            'doc_id' => $doc_id,
                            'call_type' => $call_type
                        ],
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'channel_id' => 'xxxx',
                                'title' => 'Message from' . $user_name,
                                'body' => 'please click to see the message'
                            ]
                        ]
                    ]);
                }
                $messaging->send($message);
                return ['code' => 0, 'data' => $to_token, 'msg' => "success"];

            } else {
                return ['code' => -1, 'data' => "", 'msg' => "device token is empty"];

            }
        } catch (\Exception $e) {
            return ['code' => -1, 'data' => "", 'msg' => (String) $e];

        }


    }

    public function bind_fcmtoken(Request $request)
    {
        $token = $request->user_token;
        print($token);

        $fcmtoken = $request->input("fcmtoken");
        if (empty($fcmtoken)) {
            return ['code' => -1, 'data' => '', 'msg' => "error getting the token"];
        }
        DB::table('users')->where('token', '=', $token)->update(['fcmtoken' => $fcmtoken]);
        return ['code' => -1, 'data' => "", 'msg' => 'error getting the token'];
    }

    public function upload_photo(Request $request){
        $file= $request->file('file');
        try{
            $extension=$file->getClientOriginalExtension();
            $fullfileName=uniqid().'.'.$extension;
            $timeDir=date('ymd');
            $file->storeAs($timeDir,$fullfileName,['disk'=>'public']);
            $url=env('APP_URL')."/uploads/".$timeDir.'/'.$fullfileName;
        return ['code' => 0, 'data' => $url, 'msg' => 'success image upload'];

        }catch(Exception $e){
        return ['code' => -1, 'data' => "", 'msg' => 'error uploading image'];

        }
    }
}
<?php
/**
 * @Author huaixiu.zhen@gmail.com
 * http://litblc.com
 * User: huaixiu.zhen
 * Date: 2019/4/22
 * Time: 20:31
 */
namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Services\OAuthService\GithubService;

class OAuthController extends Controller
{
    /**
     * 前端重定向地址
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function githubLogin()
    {
        return GithubService::githubLogin();
    }

    /**
     * 授权登录后的回调
     * 使用第三方登录成功后，判断是否有用户有该GitHub_id，若有则生成token返回，无则创建用户生成token返回
     * 之后判断是否曾经绑定过邮箱或者手机号，有则binding_status返回true
     * 若binding_status为false,则弹出提示绑定手机或者邮箱（类似注册页面，逻辑类似）
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function githubCallback(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required',
            'state' => 'required',
        ]);

        if ($validator->fails()) {
            $response = json_encode(
                ['message' => $validator->errors()->first()]
//                Response::HTTP_BAD_REQUEST
            );
        } else {
            $response = GithubService::githubCallback(
                $request->get('code')
            );
        }

        return view('oauth.github', ['response' => $response]);
    }
}

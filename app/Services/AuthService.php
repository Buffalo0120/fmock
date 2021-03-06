<?php
/**
 * @Author huaixiu.zhen@gmail.com
 * http://litblc.com
 * User: huaixiu.zhen
 * Date: Response::HTTP_CREATED8/8/22
 * Time: 20:35
 */
namespace App\Services;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use App\Services\BaseService\RegexService;
use App\Repositories\Eloquent\UserRepository;

class AuthService extends Service
{
    private $redisService;

    private $emailService;

    private $userRepository;

    /**
     * AuthService constructor.
     *
     * @param RedisService   $redisService
     * @param EmailService   $emailService
     * @param UserRepository $userRepository
     */
    public function __construct(
        RedisService $redisService,
        EmailService $emailService,
        UserRepository $userRepository
    ) {
        $this->redisService = $redisService;
        $this->emailService = $emailService;
        $this->userRepository = $userRepository;
    }

    /**
     * 发送注册码服务 支持email和短信服务
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $account
     * @param $type
     *
     * @throws \AlibabaCloud\Client\Exception\ClientException
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendRegisterCode($account, $type)
    {
        // 同一IP写入限制，防止用户通过大量账号强行注入
        if ($this->verifyIpLimit('register')) {
            return response()->json(
                ['message' => __('app.request_too_much')],
                Response::HTTP_FORBIDDEN
            );
        }

        // 正常逻辑
        if ($this->redisService->isRedisExists('user:register:account:' . $account)) {
            return response()->json(
                ['message' => __('app.account_ttl') . $this->redisService->getRedisTtl('user:register:account:' . $account) . 's'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } else {
            // 生成验证码
            $code = self::code();

            switch ($type) {

                // 邮箱
                case 'email':
                    $data = ['data' => __('app.verify_code') . $code . __('app.email_error')];
                    $subject = __('app.fmock_register_service');
                    $res = $this->emailService->sendEmail($account, $data, $subject);
                    if ($res) {
                        $this->redisService->setRedis('user:register:account:' . $account, $code, 'EX', 600);

                        return response()->json(
                            ['message' => __('app.send_email') . __('app.success')],
                            Response::HTTP_OK
                        );
                    }
                    break;

                // 手机短信
                case 'mobile':
                    $data = ['code' => $code];
                    $res = SmsService::sendSms($account, json_encode($data), 'FMock');
                    if (is_array($res) && $res['Code'] === 'OK') {
                        $this->redisService->setRedis('user:register:account:' . $account, $code, 'EX', 600);

                        return response()->json(
                            ['message' => __('app.send_mobile') . __('app.success')],
                            Response::HTTP_OK
                        );
                    } else {
                        return response()->json(
                            ['message' => is_array($res) ? $res['Message'] : $res],
                            Response::HTTP_INTERNAL_SERVER_ERROR
                        );
                    }
                    break;
            }

            return response()->json(
                ['message' => __('app.try_again')],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * 发送改密验证码服务 支持email和短信服务
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $account
     * @param $type
     *
     * @throws \AlibabaCloud\Client\Exception\ClientException
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendPasswordCode($account, $type)
    {
        // 同一IP写入限制，防止用户通过大量账号强行注入
        if ($this->verifyIpLimit('password-code')) {
            return response()->json(
                ['message' => __('app.request_too_much')],
                Response::HTTP_FORBIDDEN
            );
        }

        if ($this->redisService->isRedisExists('user:password:account:' . $account)) {
            return response()->json(
                ['message' => __('app.account_ttl') . $this->redisService->getRedisTtl('user:password:account:' . $account) . 's'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } else {
            $code = self::code();

            switch ($type) {

                // email
                case 'email':
                    $data = ['data' => __('app.verify_code') . $code . __('app.email_error'),];
                    $subject = __('app.fmock_reset_pwd_service');
                    $res = $this->emailService->sendEmail($account, $data, $subject);

                    if ($res) {
                        $this->redisService->setRedis('user:password:account:' . $account, $code, 'EX', 600);

                        return response()->json(
                            ['message' => __('app.send_email') . __('app.success')],
                            Response::HTTP_OK
                        );
                    }
                    break;

                // mobile
                case 'mobile':
                    $data = ['code' => $code];
                    $res = SmsService::sendSms($account, json_encode($data), 'FMock');
                    if (is_array($res) && $res['Code'] === 'OK') {
                        $this->redisService->setRedis('user:password:account:' . $account, $code, 'EX', 600);

                        return response()->json(
                            ['message' => __('app.send_mobile') . __('app.success')],
                            Response::HTTP_OK
                        );
                    } else {
                        return response()->json(
                            ['message' => is_array($res) ? $res['Message'] : $res],
                            Response::HTTP_INTERNAL_SERVER_ERROR
                        );
                    }
                    break;
            }

            return response()->json(
                ['message' => __('app.try_again')],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * 注册服务
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $name
     * @param $password
     * @param $account
     * @param $verifyCode
     * @param $type
     *
     * @return array
     */
    public function register($name, $password, $account, $verifyCode, $type)
    {
        $code = $this->redisService->getRedis('user:register:account:' . $account);

        if ($code) {
            if ($code == $verifyCode) {
                $uuid = self::uuid('user-');
                $user = $this->userRepository->create([
                    'name' => $name,
                    'password' => bcrypt($password),
                    $type => $account,
                    'uuid' => $uuid,
                ]);
                $token = $user->createToken(env('APP_NAME'))->accessToken;

                return response()->json(
                    ['access_token' => $token],
                    Response::HTTP_CREATED
                );
            } else {
                return response()->json(
                    ['message' => __('app.verify_code') . __('app.error')],
                    Response::HTTP_UNAUTHORIZED
                );
            }
        }

        return response()->json(
            ['message' => __('app.verify_code') . __('app.nothing_or_expire')],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    /**
     * 登录服务
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $account
     * @param $password
     * @param $type
     *
     * @return array
     */
    public function login($account, $password, $type)
    {
        // 同一IP写入限制，防止用户通过大量账号强行注入
        if ($this->verifyIpLimit('login')) {
            return response()->json(
                ['message' => __('app.request_too_much')],
                Response::HTTP_FORBIDDEN
            );
        }

        $getFirstUserFunc = 'getFirstUserBy' . ucfirst($type);
        $user = $this->userRepository->$getFirstUserFunc($account);

        if ($user && $user->closure == 'none') {
            if ($this->verifyPasswordLimit($account)) {
                return response()->json(
                    ['message' => __('app.request_too_much')],
                    Response::HTTP_FORBIDDEN
                );
            }

            if (Auth::attempt([$type => $account, 'password' => $password])) {
                $token = $user->createToken(env('APP_NAME'))->accessToken;

                return response()->json(
                    ['accessToken' => $token, 'userInfo' => Auth::user()],
                    Response::HTTP_OK
                );
            } else {
                return response()->json(
                    ['message' => __('app.password') . __('app.error')],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        return response()->json(
            ['message' => __('app.user_is_closure')],
            Response::HTTP_BAD_REQUEST
        );
    }

    /**
     * 改密服务
     *
     * @Author huaixiu.zhen@gmail.com
     * http://litblc.com
     *
     * @param $account
     * @param $verifyCode
     * @param $password
     * @param $type
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword($account, $verifyCode, $password, $type)
    {
        // 同一IP写入限制，防止用户通过大量账号强行注入
        if ($this->verifyIpLimit('password-change')) {
            return response()->json(
                ['message' => __('app.request_too_much')],
                Response::HTTP_FORBIDDEN
            );
        }

        $code = $this->redisService->getRedis('user:password:account:' . $account);

        if ($code) {
            if ($code == $verifyCode) {
                $getFirstUserFunc = 'getFirstUserBy' . ucfirst($type);
                $user = $this->userRepository->$getFirstUserFunc($account);
                $user->password = bcrypt($password);
                $user->save();

                return response()->json(
                    ['message' => __('app.change') . __('app.success')],
                    Response::HTTP_OK
                );
            } else {
                return response()->json(
                    ['message' => __('app.verify_code') . __('app.error')],
                    Response::HTTP_UNAUTHORIZED
                );
            }
        }

        return response()->json(
            ['message' => __('app.verify_code') . __('app.nothing_or_expire')],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    /**
     * 获取用户信息
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $uuid
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserByUuid($uuid)
    {
        $user = $this->userRepository->findBy('uuid', $uuid, ['id', 'email', 'name', 'avatar', 'gender', 'birthday', 'reside_city', 'bio', 'created_at']);

        if ($user) {
            return response()->json(
                ['data' => $user],
                Response::HTTP_OK
            );
        }

        return response()->json(
            ['message' => __('app.user_is_closure')],
            Response::HTTP_NOT_FOUND
        );
    }

    /**
     * 获取当前登录用户信息
     *
     * @Author huaixiu.zhen@gmail.com
     * http://litblc.com
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function myInfo()
    {
        return response()->json(
            ['data' => Auth::user()],
            Response::HTTP_OK
        );
    }

    /**
     * 修改个人信息 (不包括昵称)
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param array $data
     *
     * @return mixed
     */
    public function updateMyInfo(array $data)
    {
        $user = Auth::user();

        if ($this->userRepository->update($data, $user->id)) {
            return response()->json(
                ['data' => $this->userRepository->find($user->id)],
                Response::HTTP_OK
            );
        } else {
            return response()->json(
                ['message' => __('app.try_again')],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * 修改用户昵称
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $name
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateMyName($name)
    {
        $user = Auth::user();

        if ($user->is_rename == 'yes') {
            $user->name = $name;
            $user->is_rename = 'none';

            if ($user->save()) {
                return response()->json(
                    ['data' => $user->name],
                    Response::HTTP_OK
                );
            }

            return response()->json(
                ['message' => __('app.try_again')],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        } else {

            // TODO 增加扫码改名逻辑
            return response()->json(
                ['message' => __('app.rename_limit')],
                Response::HTTP_FORBIDDEN
            );
        }
    }

    /**
     * 登出
     *
     * @Author huaixiu.zhen@gmail.com
     * http://litblc.com
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        Auth::guard('api')->user()->token()->delete();

        return response()->json(
            ['message' => __('app.logout') . __('app.success')],
            Response::HTTP_OK
        );
    }

    /**
     * 判断当前账号状态，是否存在和冻结
     * 用于输入框缺失焦点时触发
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $account
     * @param $type
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAccountStatus($account, $type)
    {
        // 同一IP写入限制，防止用户通过大量账号强行注入
        if ($this->verifyIpLimit('account-status')) {
            return response()->json(
                ['message' => __('app.request_too_much')],
                Response::HTTP_FORBIDDEN
            );
        }

        $getFirstUserFunc = 'getFirstUserBy' . ucfirst($type);
        $user = $this->userRepository->$getFirstUserFunc($account);

        if ($user && $user->closure == 'none') {
            return response()->json(
                null,
                Response::HTTP_NO_CONTENT
            );
        }

        return response()->json(
            ['message' => __('app.user_is_closure')],
            Response::HTTP_BAD_REQUEST
        );
    }

    /**
     * 正则判断是email还是mobile
     * 返回字段与数据库一致
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $account
     *
     * @return string 'email'/'mobile'
     */
    public function regexAccountType($account)
    {
        $type = '';

        if (RegexService::test('email', $account)) {
            $type = 'email';
        }

        if (RegexService::test('mobile', $account)) {
            $type = 'mobile';
        }

        return $type;
    }

    /**
     * 密码错误限制
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $account
     *
     * @return bool
     */
    private function verifyPasswordLimit($account)
    {
        if ($this->redisService->isRedisExists('login:times:' . $account)) {
            $this->redisService->redisIncr('login:times:' . $account);

            if ($this->redisService->getRedis('login:times:' . $account) > 5) {
                return true;
            }

            return false;
        } else {
            $this->redisService->setRedis('login:times:' . $account, 1, 'EX', 600);

            return false;
        }
    }

    /**
     * ip操作限制，最多60分钟内请求5次
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $action // 区分动作
     *
     * @return bool
     */
    private function verifyIpLimit($action)
    {
        $clientIp = self::getClientIp();

        if ($this->redisService->isRedisExists('ip:' . $action . ':times:' . $clientIp)) {
            $this->redisService->redisIncr('ip:' . $action . ':times:' . $clientIp);

            if ($this->redisService->getRedis('ip:' . $action . ':times:' . $clientIp) > 5) {
                return true;
            }

            return false;
        } else {
            $this->redisService->setRedis('ip:' . $action . ':times:' . $clientIp, 1, 'EX', 3600);

            return false;
        }
    }
}

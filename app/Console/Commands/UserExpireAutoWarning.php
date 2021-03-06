<?php

namespace App\Console\Commands;

use App\Components\Helpers;
use Illuminate\Console\Command;
use App\Http\Models\User;
use App\Http\Models\EmailLog;
use App\Mail\userExpireWarning;
use App\Mail\userExpireWarningToday;
use Mail;
use Log;

class UserExpireAutoWarning extends Command
{
    protected $signature = 'userExpireAutoWarning';
    protected $description = '用户临近到期自动发邮件提醒';
    protected static $systemConfig;

    public function __construct()
    {
        parent::__construct();
        self::$systemConfig = Helpers::systemConfig();
    }

    public function handle()
    {
        $jobStartTime = microtime(true);

        // 用户临近到期自动发邮件提醒
        if (self::$systemConfig['expire_warning']) {
            $this->userExpireWarning();
        }

        $jobEndTime = microtime(true);
        $jobUsedTime = round(($jobEndTime - $jobStartTime), 4);

        Log::info('执行定时任务【' . $this->description . '】，耗时' . $jobUsedTime . '秒');
    }

    private function userExpireWarning()
    {
        // 只取SSR没被禁用的用户，其他不用管
        $userList = User::query()->where('enable', 1)->get();
        foreach ($userList as $user) {
            // 用户名不是邮箱的跳过
            if (false === filter_var($user->username, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // 计算剩余可用时间
            $lastCanUseDays = ceil(round(strtotime($user->expire_time) - strtotime(date('Y-m-d H:i:s'))) / 3600 / 24);
            if ($lastCanUseDays == 0) {
                $title = '账号过期提醒';
                $content = '您的账号将于今天晚上【24:00】过期。';

                try {
                    Mail::to($user->username)->send(new userExpireWarningToday(self::$systemConfig['website_name']));
                    $this->sendEmailLog($user->id, $title, $content);
                } catch (\Exception $e) {
                    $this->sendEmailLog($user->id, $title, $content, 0, $e->getMessage());
                }
            } elseif ($lastCanUseDays > 0 && $lastCanUseDays <= self::$systemConfig['expire_days']) {
                $title = '账号过期提醒';
                $content = '您的账号还剩' . $lastCanUseDays . '天即将过期。';

                try {
                    Mail::to($user->username)->send(new userExpireWarning(self::$systemConfig['website_name'], $lastCanUseDays));
                    $this->sendEmailLog($user->id, $title, $content);
                } catch (\Exception $e) {
                    $this->sendEmailLog($user->id, $title, $content, 0, $e->getMessage());
                }
            }
        }
    }

    /**
     * 写入邮件发送日志
     *
     * @param int    $user_id 用户ID
     * @param string $title   标题
     * @param string $content 内容
     * @param int    $status  投递状态
     * @param string $error   投递失败时记录的异常信息
     */
    private function sendEmailLog($user_id, $title, $content, $status = 1, $error = '')
    {
        $emailLogObj = new EmailLog();
        $emailLogObj->user_id = $user_id;
        $emailLogObj->title = $title;
        $emailLogObj->content = $content;
        $emailLogObj->status = $status;
        $emailLogObj->error = $error;
        $emailLogObj->created_at = date('Y-m-d H:i:s');
        $emailLogObj->save();
    }
}

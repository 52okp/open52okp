<?php
declare(strict_types=1);
namespace Okp;
use PHPMailer\PHPMailer\PHPMailer;
final class Mailer {
 public function __construct(private Settings $settings){}
 public function send(string $email,string $code,string $purpose):void {
  $s=$this->settings->get('smtp');if(empty($s['host'])||empty($s['from']))throw new Problem('邮件服务尚未配置，请联系管理员',503);
  $mail=new PHPMailer(true);$mail->isSMTP();$mail->CharSet='UTF-8';$mail->Host=$s['host'];$mail->Port=(int)($s['port']??587);$mail->Timeout=10;$mail->SMTPAuth=!empty($s['username']);$mail->Username=$s['username']??'';$mail->Password=$s['password']??'';
  $mail->SMTPSecure=($s['security']??'tls')==='ssl'?PHPMailer::ENCRYPTION_SMTPS:PHPMailer::ENCRYPTION_STARTTLS;
  $mail->setFrom($s['from'],'52okp 账号中心');$mail->addAddress($email);$mail->Subject='52okp '.(['register'=>'注册','reset'=>'找回密码','link-email'=>'绑定邮箱'][$purpose]??'账号').'验证码';$mail->Body="你的验证码是：{$code}。5 分钟内有效。请勿向他人提供；非本人操作请忽略。";
  try{$mail->send();}catch(\Throwable){throw new Problem('邮件暂时发送失败，请稍后重试',503);}
 }
}

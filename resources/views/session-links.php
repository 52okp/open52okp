<?php if($isAdmin||$isAuth): ?><a class="session-link" href="/account"><?= $icon('user') ?><span>我的账号</span></a><?php endif ?>
<?php if(!$isAdmin&&$user['role']==='admin'): ?><a class="session-link" href="/admin"><?= $icon('settings') ?><span>管理后台</span></a><?php endif ?>
<form class="session-logout" method="post" action="/logout"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><button class="text" type="submit" aria-label="退出登录"><?= $icon('logout') ?><span>退出登录</span></button></form>

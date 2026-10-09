<?php $applicationNames=array_column($applicationOptions,'name','id');$pageUrl=fn($number)=>'?'.http_build_query(['section'=>'users','page'=>$number,'q'=>$q,'client'=>$clientFilter,'relation'=>$relationFilter]); ?>
<section class="card" id="members">
 <div class="section-heading"><h2>用户列表</h2><span class="muted">共 <?= $totalMembers ?> 位 · 第 <?= $page ?> / <?= $totalPages ?> 页</span></div>
 <form method="get" class="member-search member-filters" data-application-filter>
  <input type="hidden" name="section" value="users">
  <label class="search-field"><span class="sr-only">搜索账号、邮箱或昵称</span><?= $icon('search') ?><input name="q" value="<?= $e($q) ?>" placeholder="搜索账号、邮箱或昵称"></label>
  <label class="filter-field"><span>应用</span><select name="client"><option value="">全部应用</option><?php foreach($applicationOptions as $option): ?><option value="<?= $e($option['id']) ?>" <?= $clientFilter===$option['id']?'selected':'' ?>><?= $e($option['name']) ?><?= $option['enabled']?'':'（停用）' ?></option><?php endforeach ?></select></label>
  <label class="filter-field"><span>应用登录记录</span><select name="relation" <?= $clientFilter===''?'disabled':'' ?>><option value="all" <?= $relationFilter!=='unrecorded'?'selected':'' ?>>有登录记录</option><option value="unrecorded" <?= $relationFilter==='unrecorded'?'selected':'' ?>>暂无登录记录</option></select></label>
  <button>筛选</button><?php if($q!==''||$clientFilter!==''): ?><a href="?section=users">清除</a><?php endif ?>
 </form>
 <p class="muted member-evidence-note">选择应用后，可查看有记录或暂无记录的用户。应用记录区分完成授权和已签发登录凭证；历史记录已清理的情况无法补回。</p>
 <div class="table"><table class="members-table"><thead><tr><th>用户</th><th>邮箱</th><th>注册来源</th><th>应用记录</th><th>状态</th><th>操作</th></tr></thead><tbody>
 <?php foreach($members as $m): ?><tr>
  <td><div class="table-user"><span class="avatar" aria-hidden="true"><?= $e(mb_substr($m['display_name'],0,1)) ?></span><div><strong><?= $e($m['display_name']) ?></strong><small><?= $e($m['username']) ?></small></div></div></td>
  <td><?= $e($m['email']??'—') ?></td>
  <td class="member-source"><strong><?= $e($m['registration_platform']) ?></strong><small><?= $e($m['registration_source']) ?></small></td>
  <td class="member-applications"><div class="application-tags"><?php foreach(array_slice($m['applications'],0,3,true) as $applicationId=>$record): ?><span class="status-pill <?= $record['exchanged']?'good':'neutral' ?>" title="<?= $record['exchanged']?'已签发登录凭证':'已完成授权，暂无凭证签发记录' ?>"><?= $e($applicationNames[$applicationId]??$applicationId) ?></span><?php endforeach ?><?php if(!$m['applications']): ?><span class="muted">暂无应用登录记录</span><?php endif ?></div><a class="member-detail-link" href="/admin/user-applications?id=<?= $e(urlencode($m['id'])) ?>">查看应用明细（<?= count($m['applications']) ?>）</a></td>
  <td><span class="status-pill <?= $m['enabled']?'good':'neutral' ?>"><?= $m['enabled']?'正常':'禁用' ?></span></td>
  <td><?php if($m['role']!=='admin'): ?><form method="post" action="/admin/user"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= $e($m['id']) ?>"><input type="hidden" name="enabled" value="<?= $m['enabled']?'0':'1' ?>"><button class="secondary button-small"><?= $m['enabled']?'禁用':'启用' ?></button></form><?php else: ?><span class="muted">管理员</span><?php endif ?></td>
 </tr><?php endforeach ?>
 <?php if(!$members): ?><tr><td colspan="6"><div class="empty-state"><?= $icon('search') ?><strong>没有找到匹配的用户</strong><p>调整关键词或应用筛选条件。</p></div></td></tr><?php endif ?>
 </tbody></table></div>
 <div class="pagination"><?php if($page>1): ?><a class="button secondary button-small" href="<?= $e($pageUrl($page-1)) ?>">上一页</a><?php else: ?><span class="button secondary button-small disabled" aria-disabled="true">上一页</span><?php endif ?><span class="muted">第 <?= $page ?> / <?= $totalPages ?> 页</span><?php if($page<$totalPages): ?><a class="button secondary button-small" href="<?= $e($pageUrl($page+1)) ?>">下一页</a><?php else: ?><span class="button secondary button-small disabled" aria-disabled="true">下一页</span><?php endif ?></div>
</section>
<details class="card audit-panel"><summary>登录与管理审计记录<span class="muted">最近 50 条</span></summary><section class="card"><div class="table"><table><thead><tr><th>时间（UTC）</th><th>事件</th><th>账号 ID</th><th>说明</th></tr></thead><tbody><?php foreach($events as $event): ?><tr><td><?= $e(gmdate('Y-m-d H:i:s',(int)$event['created_at'])) ?></td><td><?= $e($event['event']) ?></td><td><?= $e($event['user_id']??'—') ?></td><td><?= $e($event['detail']) ?></td></tr><?php endforeach ?><?php if(!$events): ?><tr><td colspan="4" class="muted">暂无审计记录。</td></tr><?php endif ?></tbody></table></div></section></details>

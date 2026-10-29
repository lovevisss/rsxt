<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, FilePenLine } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
type RequestItem = { id: number; employee_number?: string; status: string; reason: string; created_at: string; reviewed_at: string | null; applied_at: string | null };
const props = defineProps<{ modules: Record<string, Module>; reviewMode: boolean; requests: { data: RequestItem[]; current_page: number; last_page: number; total: number } }>();
const statusText: Record<string, string> = { pending: '待审核', withdrawn: '已撤回', rejected: '已驳回', applying: '写入中', applied: '已生效', conflict: '数据冲突', apply_failed: '写入失败' };
const pageUrl = (page: number) => `${props.reviewMode ? '/personnel/admin/profile-corrections' : '/personnel/corrections'}?page=${page}`;
</script>

<template>
    <Head :title="reviewMode ? '档案更正审核' : '我的更正申请'" />
    <PersonnelShell :modules="modules" :title="reviewMode ? '档案更正审核' : '我的更正申请'" eyebrow="PERSONNEL FILE / CORRECTIONS" :active-key="reviewMode ? 'correction_review' : 'corrections'">
        <div class="correction-list-head"><div><FilePenLine :size="20" /><p>{{ reviewMode ? '核对员工提交的原值、申请值与证明材料，整单审核。' : '查看更正申请和审核结果。待审内容不会改变当前档案。' }}</p></div><Link v-if="!reviewMode" href="/personnel/corrections/new" class="correction-primary">发起更正申请 <ArrowRight :size="15" /></Link></div>
        <div class="correction-list"><div v-if="!requests.data.length" class="correction-empty">{{ reviewMode ? '暂无更正申请' : '您还没有提交更正申请' }}</div>
            <Link v-for="item in requests.data" :key="item.id" :href="`/personnel/corrections/${item.id}`" class="correction-list-row"><span class="correction-list-number">NO. {{ item.id }}</span><div><strong>{{ reviewMode ? `${item.employee_number} · ` : '' }}{{ item.reason }}</strong><small>{{ item.created_at }}</small></div><span :class="['correction-status', item.status]">{{ statusText[item.status] || item.status }}</span><ArrowRight :size="16" /></Link>
        </div>
        <nav v-if="requests.last_page > 1" class="history-pagination" aria-label="更正申请分页"><Link v-if="requests.current_page > 1" :href="pageUrl(requests.current_page - 1)">上一页</Link><span>第 {{ requests.current_page }} / {{ requests.last_page }} 页</span><Link v-if="requests.current_page < requests.last_page" :href="pageUrl(requests.current_page + 1)">下一页</Link></nav>
    </PersonnelShell>
</template>

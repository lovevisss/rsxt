<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
import '@/../css/leave.css';

type Module = { menu_id: number; table: string; title: string };
type Detail = { id: number; person: string; type: string; start: string; end: string; timeType: string; note: string; duration: string; hasAttachment: boolean };
const props = defineProps<{
    modules: Record<string, Module>; kind: string;
    record: { id: number; name: string; department: string; summary: string; date: string; status: string; currentReviewer: string; result: string; rejection: string; submittedAt: string | null; originalDetailId: number | null; daysEarly: number | null; version: string };
    details: Detail[]; auditors: { auditorName: string; step: number; workflowType: string }[];
    events: { actor: string; action: string; note: string | null; created_at: string }[];
    canEdit: boolean; canSubmit: boolean; canWithdraw: boolean; canReview: boolean; writerAvailable: boolean; readOnlyPreview: boolean;
}>();
const note = ref('');
const enabled = props.writerAvailable && !props.readOnlyPreview;
function action(name: string) {
    router.post(`/personnel/leave/${props.kind}/${props.record.id}/${name}`, { version: props.record.version, note: note.value }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="kind === 'a34' ? '请假申请详情' : '销假申请详情'" />
    <PersonnelShell :modules="modules" :title="kind === 'a34' ? '请假申请详情' : '销假申请详情'" eyebrow="LEAVE / 申请详情" :active-key="canReview ? 'leave_review' : kind === 'a34' ? 'leave' : 'leave_cancel'">
        <div class="leave-header"><div class="leave-lede"><strong>{{ record.name }} · {{ record.summary }}</strong><p>申请日期 {{ record.date || '—' }} · {{ record.department }}</p></div><span class="leave-badge">{{ record.status }}</span></div>
        <div v-if="!enabled" class="leave-notice">写入尚未启用，当前仅可查看。</div>
        <div class="leave-actions top"><Link :href="kind === 'a34' ? '/personnel/leave' : '/personnel/leave/cancellations'" class="leave-link">返回列表</Link><Link v-if="canEdit" :href="`/personnel/leave/${kind}/${record.id}/edit`" class="leave-link">编辑草稿</Link><button v-if="canSubmit" :disabled="!enabled" class="leave-primary" @click="action('submit')">提交审批</button><button v-if="canWithdraw" :disabled="!enabled" class="leave-secondary" @click="action('withdraw')">撤销提交</button></div>
        <section class="leave-card leave-info"><h2>申请信息</h2><dl><div><dt>申请人</dt><dd>{{ record.name }}</dd></div><div><dt>部门</dt><dd>{{ record.department }}</dd></div><div><dt>当前审批人</dt><dd>{{ record.currentReviewer || '—' }}</dd></div><div><dt>提交时间</dt><dd>{{ record.submittedAt || '—' }}</dd></div><div v-if="kind === 'a38'"><dt>原请假明细编号</dt><dd>{{ record.originalDetailId }}</dd></div><div v-if="kind === 'a38'"><dt>提前销假天数</dt><dd>{{ record.daysEarly ?? '—' }}</dd></div><div><dt>审批结果</dt><dd class="multiline">{{ record.result || '—' }}</dd></div><div><dt>退回意见</dt><dd>{{ record.rejection || '—' }}</dd></div></dl></section>
        <section v-if="kind === 'a34'" class="leave-card"><h2>请假明细</h2><div v-for="detail in details" :key="detail.id" class="leave-detail"><strong>{{ detail.person }} · {{ detail.type }}</strong><span>{{ detail.start }} 至 {{ detail.end }} · {{ detail.timeType }}</span><small>{{ detail.duration }}</small><p v-if="detail.note">{{ detail.note }}</p><a v-if="detail.hasAttachment" :href="`/personnel/leave/attachments/${detail.id}`" target="_blank" rel="noopener">查看附件</a></div></section>
        <section v-if="auditors.length" class="leave-card"><h2>审批流程</h2><div v-for="auditor in auditors" :key="auditor.step" class="leave-flow"><span>{{ auditor.step }}</span><strong>{{ auditor.auditorName }}</strong><small>{{ auditor.workflowType }}</small></div></section>
        <section v-if="canReview" class="leave-card leave-review"><h2>处理申请</h2><label>审批意见<textarea v-model="note" rows="3" maxlength="200" placeholder="通过可选填；退回时必填原因" /></label><div class="leave-actions"><button class="leave-primary" :disabled="!enabled" @click="action('approve')">通过</button><button class="leave-secondary" :disabled="!enabled || !note.trim()" @click="action('return')">退回修改</button></div></section>
        <section v-if="events.length" class="leave-card"><h2>操作记录</h2><div v-for="event in events" :key="`${event.action}-${event.created_at}`" class="leave-event"><strong>{{ event.actor }} · {{ event.action }}</strong><span>{{ event.note || '—' }}</span><time>{{ event.created_at }}</time></div></section>
    </PersonnelShell>
</template>

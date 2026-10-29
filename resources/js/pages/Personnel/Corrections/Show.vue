<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Paperclip, ShieldCheck } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
type Correction = { id: number; applicant_user_id: number; employee_number: string; status: string; reason: string; review_note: string | null; apply_error: string | null; created_at: string; reviewed_at: string | null; applied_at: string | null };
const props = defineProps<{
    modules: Record<string, Module>; correction: Correction;
    changes: { field: string; label: string; old: string | null; new: string | null }[];
    files: { id: number; original_name: string; mime: string; size: number }[];
    events: { action: string; note: string | null; created_at: string }[];
    canReview: boolean; writerAvailable: boolean; readOnlyPreview: boolean;
}>();
const rejectForm = useForm({ note: '' });
const statusText: Record<string, string> = { pending: '待审核', withdrawn: '已撤回', rejected: '已驳回', applying: '写入中', applied: '已生效', conflict: '数据冲突', apply_failed: '写入失败' };
const eventText: Record<string, string> = { submitted: '员工提交', withdrawn: '员工撤回', approved: '审核通过', rejected: '审核驳回', applied: '档案已更新', conflict: '原始数据已变化', apply_failed: '写入失败', retry_requested: '重新尝试写入' };
const approve = () => router.post(`/personnel/admin/profile-corrections/${props.correction.id}/approve`);
const withdraw = () => router.post(`/personnel/corrections/${props.correction.id}/withdraw`);
const retry = () => router.post(`/personnel/admin/profile-corrections/${props.correction.id}/retry`);
const reject = () => rejectForm.post(`/personnel/admin/profile-corrections/${props.correction.id}/reject`);
</script>

<template>
    <Head :title="`更正申请 #${correction.id}`" />
    <PersonnelShell :modules="modules" :title="`更正申请 #${correction.id}`" eyebrow="PERSONNEL FILE / CORRECTION DETAIL" :active-key="canReview ? 'correction_review' : 'corrections'">
        <Link :href="canReview ? '/personnel/admin/profile-corrections' : '/personnel/corrections'" class="correction-back"><ArrowLeft :size="15" />返回列表</Link>
        <section class="correction-detail-head"><div><small>申请编号 / {{ correction.id }}</small><h2>{{ correction.employee_number }} 的档案更正</h2><p>{{ correction.reason }}</p></div><span :class="['correction-status', correction.status]">{{ statusText[correction.status] || correction.status }}</span></section>
        <section class="correction-panel"><header><div><span>PROPOSED CHANGES</span><h2>字段变更</h2></div><small>{{ changes.length }} 项</small></header><div class="correction-detail-diff"><div v-for="change in changes" :key="change.field"><label>{{ change.label }}</label><span>{{ change.old || '未填写' }}</span><ArrowRight :size="15" /><strong>{{ change.new || '未填写' }}</strong></div></div></section>
        <section class="correction-panel"><header><div><span>SUPPORTING FILES</span><h2>证明材料</h2></div></header><div v-if="files.length" class="correction-files"><a v-for="file in files" :key="file.id" :href="`/personnel/corrections/${correction.id}/files/${file.id}`"><Paperclip :size="15" />{{ file.original_name }}<small>{{ Math.round(file.size / 1024) }} KB</small></a></div><p v-else class="correction-empty">未上传证明材料</p></section>
        <section v-if="correction.review_note || correction.apply_error" class="correction-feedback"><p v-if="correction.review_note"><strong>审核意见：</strong>{{ correction.review_note }}</p><p v-if="correction.apply_error"><strong>写入状态：</strong>{{ correction.apply_error }}</p></section>
        <section v-if="canReview && correction.status === 'pending'" class="correction-panel correction-review-panel"><header><div><span>HR REVIEW</span><h2><ShieldCheck :size="19" />人力资源部审核</h2></div></header><p v-if="readOnlyPreview" class="correction-warning">本机预览仅供只读核对，不能执行审核。</p><p v-else-if="!writerAvailable" class="correction-warning">档案写入尚未启用。请先在隔离环境验收，当前不能通过申请。</p><button type="button" class="correction-primary" :disabled="readOnlyPreview || !writerAvailable" @click="approve">整单通过并写回</button><form @submit.prevent="reject"><label for="reject-note">驳回原因</label><textarea id="reject-note" v-model="rejectForm.note" class="correction-input" rows="3" maxlength="2000" required /><p v-if="rejectForm.errors.note" class="correction-error">{{ rejectForm.errors.note }}</p><button type="submit" class="correction-danger" :disabled="readOnlyPreview || rejectForm.processing">整单驳回</button></form></section>
        <section v-if="canReview && correction.status === 'apply_failed'" class="correction-panel correction-review-panel"><header><div><span>RETRY</span><h2>写入失败</h2></div></header><button type="button" class="correction-primary" :disabled="readOnlyPreview || !writerAvailable" @click="retry">重新尝试写入</button></section>
        <button v-if="!canReview && correction.status === 'pending'" type="button" class="correction-withdraw" :disabled="readOnlyPreview" @click="withdraw">撤回这张申请</button>
        <section class="correction-events"><h3>处理记录</h3><ol><li v-for="(event, index) in events" :key="index"><span>{{ eventText[event.action] || event.action }}</span><small>{{ event.created_at }}</small><p v-if="event.note">{{ event.note }}</p></li></ol></section>
    </PersonnelShell>
</template>

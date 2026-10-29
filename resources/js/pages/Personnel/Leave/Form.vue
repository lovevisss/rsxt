<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
import '@/../css/leave.css';

type Module = { menu_id: number; table: string; title: string };
type Option = { id: number; name: string };
type Person = { userId: number; name: string; personId: number };
type Detail = { id?: number; person_id: number | string; leave_type: number | string; time_type: number | string; start: string; end: string; note: string; attachment?: File | null; hasAttachment?: boolean };
const props = defineProps<{
    modules: Record<string, Module>; kind: string; record: { id: number; version: string; detail_id?: number; date?: string } | null;
    details: Detail[]; people: Person[]; types: Option[]; times: Option[];
    eligible: { id: number; label: string; start: string; end: string }[];
    writerAvailable: boolean; readOnlyPreview: boolean;
}>();
const emptyDetail = (): Detail => ({ person_id: '', leave_type: '', time_type: 3, start: '', end: '', note: '', attachment: null });
const form = useForm<{ version: string; details: Detail[]; detail_id: number | string; date: string }>({
    version: props.record?.version || '', details: props.details.length ? props.details.map(item => ({ ...item, attachment: null })) : [emptyDetail()],
    detail_id: props.record?.detail_id || '', date: props.record?.date || '',
});
const enabled = props.writerAvailable && !props.readOnlyPreview;
function submit() {
    const path = props.kind === 'a34'
        ? props.record ? `/personnel/leave/a34/${props.record.id}` : '/personnel/leave'
        : props.record ? `/personnel/leave/a38/${props.record.id}` : '/personnel/leave/cancellations';
    form.post(path, { forceFormData: props.kind === 'a34' });
}
</script>

<template>
    <Head :title="kind === 'a34' ? '请假申请' : '销假申请'" />
    <PersonnelShell :modules="modules" :title="kind === 'a34' ? '请假申请' : '销假申请'" eyebrow="LEAVE / 填写申请" :active-key="kind === 'a34' ? 'leave' : 'leave_cancel'">
        <div class="leave-header"><div class="leave-lede"><strong>{{ record ? '修改草稿' : '新建申请' }}</strong><p>保存后在详情页提交审批。</p></div><Link :href="kind === 'a34' ? '/personnel/leave' : '/personnel/leave/cancellations'" class="leave-link">返回列表</Link></div>
        <div v-if="!enabled" class="leave-notice">写入尚未启用；当前只能查看已有记录。</div>
        <form class="leave-card leave-form" @submit.prevent="submit">
            <template v-if="kind === 'a34'">
                <div v-for="(detail, index) in form.details" :key="index" class="leave-detail-editor">
                    <div class="leave-detail-title"><strong>请假明细 {{ index + 1 }}</strong><button v-if="form.details.length > 1" type="button" class="leave-link" @click="form.details.splice(index, 1)"><Trash2 :size="15" /> 删除</button></div>
                    <div class="leave-form-grid">
                        <label>请假人 *<select v-model="detail.person_id" required><option value="">请选择同部门人员</option><option v-for="person in people" :key="person.userId" :value="person.userId">{{ person.name }}</option></select></label>
                        <label>请假类型 *<select v-model="detail.leave_type" required><option value="">请选择</option><option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option></select></label>
                        <label>起始日期 *<input v-model="detail.start" type="date" required /></label>
                        <label>结束日期 *<input v-model="detail.end" type="date" required /></label>
                        <label>时间类型 *<select v-model="detail.time_type" required><option v-for="time in times" :key="time.id" :value="time.id">{{ time.name }}</option></select></label>
                        <label>证明附件（图片或 PDF，10 MB 内）<input type="file" accept="image/jpeg,image/png,application/pdf" @change="detail.attachment = ($event.target as HTMLInputElement).files?.[0] || null" /><small v-if="detail.hasAttachment">已有附件，未重新选择则保留</small></label>
                        <label class="wide">备注<textarea v-model="detail.note" rows="3" maxlength="255" /></label>
                    </div>
                </div>
                <button type="button" class="leave-add" @click="form.details.push(emptyDetail())"><Plus :size="16" /> 添加一条明细</button>
            </template>
            <div v-else class="leave-form-grid">
                <label class="wide">对应的请假明细 *<select v-model="form.detail_id" required><option value="">请选择已审核的请假</option><option v-for="item in eligible" :key="item.id" :value="item.id">{{ item.label }}</option></select></label>
                <label>销假日期 *<input v-model="form.date" type="date" required /></label>
            </div>
            <div v-if="Object.keys(form.errors).length" class="leave-errors"><p v-for="(message, key) in form.errors" :key="key">{{ message }}</p></div>
            <div class="leave-actions"><button class="leave-primary" type="submit" :disabled="!enabled || form.processing">保存草稿</button></div>
        </form>
    </PersonnelShell>
</template>

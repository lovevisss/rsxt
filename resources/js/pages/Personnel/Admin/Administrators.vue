<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ShieldCheck, UserPlus, X } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
type Module = { menu_id: number; table: string; title: string };
const props = defineProps<{
    modules: Record<string, Module>;
    admins: { employee_number: string; name: string; created_at: string }[];
}>();
const form = useForm({ employee_number: '' });
function add() {
    form.post('/personnel/admin/administrators', {
        onSuccess: () => form.reset(),
    });
}
function remove(number: string) {
    if (confirm(`移除工号 ${number} 的后台管理员权限？`))
        router.delete(`/personnel/admin/administrators/${number}`);
}
</script>
<template>
    <Head title="后台管理员" />
    <PersonnelShell
        :modules="modules"
        title="后台管理员"
        eyebrow="ADMINISTRATION / 权限配置"
        active-key="admin_accounts"
    >
        <div class="position-lead">
            <div>
                <span class="position-lead-kicker">ACCESS CONTROL</span>
                <h2>让权限对应到具体的人。</h2>
                <p>
                    后台管理员可管理岗位变动与管理员名单；原有审批权限保持独立。
                </p>
            </div>
            <ShieldCheck :size="44" />
        </div>
        <div class="position-admin-grid">
            <section class="position-panel position-admin-list">
                <div class="position-panel-title">
                    <h3>管理员名单</h3>
                    <span>{{ props.admins.length }} 人</span>
                </div>
                <div
                    v-for="admin in props.admins"
                    :key="admin.employee_number"
                    class="position-admin-row"
                >
                    <div class="position-admin-avatar">
                        {{ admin.name.slice(0, 1) }}
                    </div>
                    <div>
                        <strong>{{ admin.name }}</strong
                        ><small>{{ admin.employee_number }}</small>
                    </div>
                    <button
                        type="button"
                        aria-label="移除管理员"
                        :disabled="props.admins.length <= 1"
                        @click="remove(admin.employee_number)"
                    >
                        <X :size="16" />
                    </button>
                </div>
            </section>
            <section class="position-panel position-admin-add">
                <UserPlus :size="24" />
                <h3>添加管理员</h3>
                <p>
                    输入现有有效账号的职工编号。添加后该人员下次进入页面即可使用后台功能。
                </p>
                <form @submit.prevent="add">
                    <label
                        >职工编号<input
                            v-model="form.employee_number"
                            placeholder="例如 20150301"
                            required /></label
                    ><small v-if="form.errors.employee_number">{{
                        form.errors.employee_number
                    }}</small
                    ><button type="submit" :disabled="form.processing">
                        添加管理员
                    </button>
                </form>
            </section>
        </div>
    </PersonnelShell>
</template>

<script setup lang="ts">
defineProps<{ previewUrl: string | null }>();
</script>

<template>
    <main class="personnel-login">
        <div class="personnel-login-glow" aria-hidden="true"></div>
        <section class="personnel-login-card">
            <div class="personnel-login-mark">人</div>
            <p class="personnel-login-kicker">PERSONNEL MANAGEMENT</p>
            <h1>人事管理平台</h1>
            <p class="personnel-login-copy">信息门户单点登录尚未接入当前开发环境。正式访问需从信息门户进入。</p>
            <a v-if="previewUrl" class="personnel-login-button" :href="previewUrl">进入本机只读预览 <span aria-hidden="true">↗</span></a>
            <div v-else class="personnel-login-notice">请配置门户认证地址后再登录。</div>
            <p class="personnel-login-foot">本机预览仅用于核对页面与数据，不开放业务写入。</p>
        </section>
    </main>
</template>

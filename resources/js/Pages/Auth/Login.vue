<script setup>
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/ui/button/Button.vue';
import Input from '@/Components/ui/input/Input.vue';
import Label from '@/Components/ui/label/Label.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardHeader from '@/Components/ui/card/CardHeader.vue';
import CardTitle from '@/Components/ui/card/CardTitle.vue';
import CardDescription from '@/Components/ui/card/CardDescription.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import CardFooter from '@/Components/ui/card/CardFooter.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-background px-4">
        <Card class="w-full max-w-md">
            <CardHeader class="text-center">
                <div class="flex justify-center mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-10 w-10 text-primary">
                        <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
                        <path d="m9 12 2 2 4-4"/>
                    </svg>
                </div>
                <CardTitle class="text-2xl">PingGlass</CardTitle>
                <CardDescription>Sign in to the admin panel</CardDescription>
            </CardHeader>
            <form @submit.prevent="submit">
                <CardContent class="space-y-4">
                    <div class="space-y-2">
                        <Label for="email">Email</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            placeholder="admin@pingglass.local"
                            :disabled="form.processing"
                        />
                        <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="password">Password</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            placeholder="Enter your password"
                            :disabled="form.processing"
                        />
                    </div>
                </CardContent>
                <CardFooter class="flex flex-col gap-4">
                    <Button type="submit" class="w-full" :disabled="form.processing">
                        {{ form.processing ? 'Signing in...' : 'Sign In' }}
                    </Button>
                    <p class="text-xs text-center text-muted-foreground">
                        Default credentials: admin@pingglass.local / password
                    </p>
                </CardFooter>
            </form>
        </Card>
    </div>
</template>

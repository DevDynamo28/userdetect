<?php $__env->startSection('title', 'API Keys'); ?>
<?php $__env->startSection('page-title', 'API Keys'); ?>

<?php $__env->startSection('content'); ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200" x-data="{ showKey: false, confirmRegenerate: false, confirmRevoke: false }">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="text-sm font-medium text-gray-700">Your API Keys</h3>
    </div>

    <div class="p-6">
        
        <?php if(session('new_key')): ?>
        <div class="mb-6 bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg">
            <p class="text-sm font-medium mb-2">New API Key Generated - Copy it now! It won't be shown again in full.</p>
            <div class="flex items-center gap-2">
                <code class="bg-yellow-100 px-3 py-1 rounded text-sm font-mono break-all"><?php echo e(session('new_key')); ?></code>
                <button onclick="navigator.clipboard.writeText('<?php echo e(session('new_key')); ?>')"
                        class="px-3 py-1 bg-yellow-200 text-yellow-900 rounded text-sm hover:bg-yellow-300">
                    Copy
                </button>
            </div>
        </div>
        <?php endif; ?>

        
        <div class="border border-gray-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <span class="text-sm font-medium text-gray-700">Production Key</span>
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Active</span>
                </div>
                <span class="text-xs text-gray-400">Plan: <?php echo e(ucfirst($client->plan_type)); ?></span>
            </div>

            <div class="flex items-center gap-2 mb-4">
                <div class="flex-1 bg-gray-50 rounded px-3 py-2 font-mono text-sm" x-data="{ revealed: false }">
                    <template x-if="!revealed">
                        <span class="text-gray-500"><?php echo e(substr($client->api_key, 0, 12)); ?><?php echo e(str_repeat('*', 40)); ?>...<?php echo e(substr($client->api_key, -4)); ?></span>
                    </template>
                    <template x-if="revealed">
                        <span class="text-gray-900 break-all"><?php echo e($client->api_key); ?></span>
                    </template>
                    <button @click="revealed = !revealed" class="ml-2 text-indigo-600 text-xs hover:text-indigo-800">
                        <span x-text="revealed ? 'Hide' : 'Reveal'"></span>
                    </button>
                </div>
                <button onclick="navigator.clipboard.writeText('<?php echo e($client->api_key); ?>')"
                        class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition whitespace-nowrap">
                    Copy
                </button>
            </div>

            <div class="text-xs text-gray-400 space-y-1 mb-4">
                <p>Created: <?php echo e($client->created_at->format('M d, Y')); ?></p>
                <p>Last used: <?php echo e($client->last_api_call ? $client->last_api_call->diffForHumans() : 'Never'); ?></p>
                <p>Rate limit: <?php echo e($client->getRateLimit()); ?> requests/min</p>
                <?php if($client->allowed_domains): ?>
                    <p>Allowed domains: <?php echo e(implode(', ', $client->allowed_domains)); ?></p>
                <?php else: ?>
                    <p>Allowed domains: All (no restriction)</p>
                <?php endif; ?>
            </div>

            <div class="flex gap-2">
                <button @click="confirmRegenerate = true"
                        class="px-3 py-1.5 bg-yellow-50 text-yellow-700 border border-yellow-200 rounded-lg text-sm hover:bg-yellow-100 transition">
                    Regenerate Key
                </button>
                <button @click="confirmRevoke = true"
                        class="px-3 py-1.5 bg-red-50 text-red-700 border border-red-200 rounded-lg text-sm hover:bg-red-100 transition">
                    Revoke & Replace
                </button>
            </div>
        </div>
    </div>

    
    <div x-show="confirmRegenerate" x-cloak class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4" @click.outside="confirmRegenerate = false">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Regenerate API Key?</h3>
            <p class="text-sm text-gray-600 mb-4">This will invalidate your current API key. All integrations using the old key will stop working immediately.</p>
            <div class="flex gap-3 justify-end">
                <button @click="confirmRegenerate = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</button>
                <form method="POST" action="<?php echo e(route('dashboard.api-keys.regenerate')); ?>">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg text-sm hover:bg-yellow-700">Regenerate</button>
                </form>
            </div>
        </div>
    </div>

    
    <div x-show="confirmRevoke" x-cloak class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4" @click.outside="confirmRevoke = false">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Revoke API Key?</h3>
            <p class="text-sm text-gray-600 mb-4">This will permanently revoke the current key and generate a new one. All integrations will need to be updated.</p>
            <div class="flex gap-3 justify-end">
                <button @click="confirmRevoke = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</button>
                <form method="POST" action="<?php echo e(route('dashboard.api-keys.revoke')); ?>">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">Revoke & Replace</button>
                </form>
            </div>
        </div>
    </div>
</div>


<div class="mt-6 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <h3 class="text-sm font-medium text-gray-700 mb-4">Quick Integration</h3>
    <p class="text-sm text-gray-600 mb-3">Add this script to your website to start detecting user locations:</p>
    <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
        <pre class="text-sm text-green-400"><code>&lt;script src="<?php echo e(url('/sdk/userdetect.min.js')); ?>"&gt;&lt;/script&gt;
&lt;script&gt;
  UserDetect.init('<?php echo e($client->api_key); ?>', function(data) {
    if (data.success) {
      console.log('City:', data.location.city);
      console.log('Confidence:', data.location.confidence);
    }
  });
&lt;/script&gt;</code></pre>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.dashboard', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH D:\IP Detect\resources\views/dashboard/api-keys.blade.php ENDPATH**/ ?>
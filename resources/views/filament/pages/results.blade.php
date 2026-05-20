<x-filament-panels::page>
    {{ $this->table }}

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('post-download', (event) => {
                const data = Array.isArray(event) ? event[0] : event;
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = data.url;
                form.style.display = 'none';

                const tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = '_token';
                tokenInput.value = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                form.appendChild(tokenInput);

                data.ids.forEach((id) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'image_ids[]';
                    input.value = id;
                    form.appendChild(input);
                });

                document.body.appendChild(form);
                form.submit();
                setTimeout(() => form.remove(), 5000);
            });
        });
    </script>
</x-filament-panels::page>

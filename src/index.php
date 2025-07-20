<?php
$counter_placeholder = 0;
require_once __DIR__ .'/start-html-minification.php';
require_once __DIR__ . '/load-env.php';
?>
<!DOCTYPE html>
<html lang="en" style="color-scheme: dark;">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <style>
    <?= file_get_contents(__DIR__ . '/../public/css/app.css') ?>
  </style>

  <title>Counter App</title>
</head>

<body class="bg-gradient-to-br from-neutral-900 via-neutral-900 to-neutral-900 min-h-screen flex items-center justify-center p-4">

  <div id="counter-card" class="bg-white/10 relative overflow-hidden backdrop-blur-lg rounded-3xl p-8 shadow-2xl border border-white/20 max-w-md w-full">
    <div id="cooldown-bar" class='absolute left-0 top-0 h-1.5 bg-blue-600 right-[100%]'></div>

    <!-- Header -->
    <div class="text-center mb-8">
      <h1 id="counter" class="text-6xl font-bold text-white mb-2 tracking-tight"><?= number_format($counter_placeholder) ?></h1>
      <p class="text-neutral-300 text-sm">Current Count</p>
    </div>

    <!-- Progress indicator -->
    <div class="mb-8">
      <div class="bg-white/10 rounded-full h-2 overflow-hidden">
        <div id="progress-bar" class="bg-gradient-to-r from-blue-500 to-blue-500 h-full transition-all duration-300"
          style="width: <?= min(($counter_placeholder / 1000000) * 100, 100) ?>%"></div>
      </div>
      <p class="text-xs text-neutral-400 mt-2 text-center">
        <span data-counter-value><?= number_format(1000000 - $counter_placeholder) ?></span> to go until database deletion
      </p>
    </div>

    <!-- Action buttons -->
    <div class="flex gap-4">
      <button id="increment-btn" disabled
        class="flex-1 py-4 px-6 bg-blue-500 hover:bg-blue-500 border border-blue-500 text-blue-950 rounded-2xl transition-all duration-200 active:opacity-70 flex items-center justify-center gap-2 group disabled:opacity-50 disabled:pointer-events-none">
        <svg class="size-6 group-hover:scale-110 transition-transform" fill="currentColor" viewBox="0 0 24 24">
          <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
        </svg>
        <span class="font-medium sr-only">Increase</span>
      </button>
    </div>

    <!-- Stats footer -->
    <div class="mt-6 pt-6 border-t border-white/10">
      <div class="flex justify-between text-xs text-neutral-400">
        <span id="progress-percentage">Progress: <?= number_format(($counter_placeholder / 1000000) * 100, 2) ?>%</span>
        <span>Target: 1M</span>
      </div>
    </div>

  </div>

  <div aria-hidden="true" id="db-deleted-card" class="bg-white/10 hidden relative overflow-hidden backdrop-blur-lg rounded-3xl p-8 shadow-2xl border border-white/20 max-w-md w-full flex flex-col gap-4">
    <h1 class="text-2xl font-bold text-white">Y’ALL REALLY DID THIS.</h1>
    <p class="text-neutral-300">
      Not one dude. Not two. An entire swarm of click-hungry internet gremlins came together and absolutely <span class="font-bold text-white">deleted</span> our database.
    </p>

    <p class="text-neutral-300">
      1 million clicks. GONE. Toasted. Yeeted straight into the abyss.
    </p>

    <p class="text-neutral-300">
      I hope the shared serotonin hit was worth it, because this app? It’s running on regrets now 💀🔥
    </p>
  </div>

  <script>
    const socket = new WebSocket("ws://<?=$_ENV['APP_URL']?>:8080");
    const cooldownTime = 700; // Cooldown time in milliseconds
    let isCooldown = false;

    // Update all UI elements with the new counter value
    function updateUI(count) {
      document.getElementById('increment-btn').removeAttribute('disabled');
      const numericCount = parseInt(count.replace(/,/g, ''));
      const progressPercent = (numericCount / 1000000) * 100;
      const remaining = 1000000 - numericCount;

      // Update counter display
      document.getElementById("counter").innerText = count;

      // Update progress bar
      document.getElementById("progress-bar").style.width = `${Math.min(progressPercent, 100)}%`;

      // Update all tags with `data-counter-value` attribute
      document.querySelectorAll("[data-counter-value]").forEach((el)=> el.innerText = remaining.toLocaleString());

      // Update percentage text
      document.getElementById("progress-percentage").innerText = `Progress: ${progressPercent.toFixed(2)}%`;
    }

    // Handle cooldown animation
    function startCooldown() {
      if (isCooldown) return;

      isCooldown = true;
      const cooldownBar = document.getElementById("cooldown-bar");
      const incrementBtn = document.getElementById("increment-btn");

      // Disable button during cooldown
      incrementBtn.setAttribute('hidden', true);
      
      // Reset and animate the cooldown bar
      cooldownBar.style.transition = "none";
      cooldownBar.style.right = "100%";
      
      // Force reflow to ensure the transition works
      void cooldownBar.offsetWidth;
      
      // Start animation
      cooldownBar.animate({
        right: 0
      }, cooldownTime);
      cooldownBar.style.transitionDelay = cooldownTime;
      cooldownBar.style.right = "100%";
      cooldownBar.style.transitionDelay = 'none';
      
      // End cooldown after the specified time
      setTimeout(() => {
        isCooldown = false;
        incrementBtn.setAttribute('hidden', false);
      }, cooldownTime);
    }

    // WebSocket message handler
    socket.onmessage = (event) => {
      const data = JSON.parse(event.data);

      if (data.db_exists) {
        // update the counter
        updateUI(data.counter);
      } else {
        // DB is deleted, nuke the counter card
        const counterCard = document.getElementById("counter-card");
        if (counterCard) counterCard.remove();

        // Show the deleted message
        document.getElementById("db-deleted-card").classList.remove("hidden");
      }
    };


    // Connection status handling
    socket.onopen = () => {
      console.log("Connected to WebSocket server");
      document.getElementById("increment-btn").disabled = false;
    };

    socket.onclose = () => {
      console.log("Disconnected from WebSocket server");
      document.getElementById("increment-btn").disabled = true;
    };

    // Button click handler
    document.getElementById("increment-btn").addEventListener("click", () => {
      if (isCooldown) return;

      // Send increment command to server
      socket.send("increment");

      // Start cooldown animation
      startCooldown();
    });

    // Initialize with current value
    updateUI(document.getElementById("counter").innerText);
  </script>

</body>

</html>

<?php
include_once __DIR__.'/end-html-minification.php';
?>
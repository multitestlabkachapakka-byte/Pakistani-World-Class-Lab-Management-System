<template>
  <div>
    <h2>Dashboard</h2>
    <p>Real-time statistics will appear here once the backend and local store are connected.</p>
    <div class="cards">
      <div class="card">
        <h3>Total Tests</h3>
        <p class="big">—</p>
      </div>
      <div class="card">
        <h3>Total Patients</h3>
        <p class="big">—</p>
      </div>
      <div class="card">
        <h3>Today's Tests</h3>
        <p class="big">—</p>
      </div>
      <div class="card">
        <h3>Pending Reports</h3>
        <p class="big">—</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
// Dashboard will load live counts from the API / local SQLite in Stage 2+
</script>

<style scoped>
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 1rem;
  margin-top: 1.5rem;
}
.card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 8px;
  padding: 1.25rem;
}
.card h3 {
  margin: 0 0 0.5rem;
  font-size: 0.9rem;
  color: #6c757d;
}
.big {
  font-size: 1.75rem;
  font-weight: 700;
  margin: 0;
}
</style>

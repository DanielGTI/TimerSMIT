import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: "dist",
    emptyOutDir: true,
    rollupOptions: {
      input: {
        "work-item": "src/pages/work-item/index.html",
        timesheet: "src/pages/timesheet/index.html",
        approvals: "src/pages/approvals/index.html",
        reports: "src/pages/reports/index.html",
        settings: "src/pages/settings/index.html",
      },
    },
  },
  test: {
    environment: "jsdom",
    setupFiles: ["tests/setup.ts"],
  },
});

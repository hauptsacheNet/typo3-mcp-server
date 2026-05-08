export default {
  baseUrl: process.env.TYPO3_BASE_URL ?? 'http://localhost:8080',
  admin: {
    username: process.env.TYPO3_ADMIN_USER ?? 'admin',
    password: process.env.TYPO3_ADMIN_PASSWORD ?? 'Admin123!',
  },
};

import dotenv from 'dotenv';
dotenv.config();

const config = {
  application: {
    port: process.env.PORT,
    domainName: process.env.DOMAIN_NAME,
    appDomainName: process.env.APP_DOMAIN_NAME
  },
};

export default config;

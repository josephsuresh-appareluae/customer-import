<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Wtc\CusomerImport\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use \Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Customer\Model\AccountManagement;

class Import extends Command
{

    const FILE_NAME = "profile-name";
    const FILE_SOURCE = "source";
    const ALLOWED_FILE_EXTENSTIONS=['csv','json'];
    const REQUIRE_ADDRESS_COLUMN = ['firstname', 'lastname', 'region','city','street'];
    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $_filesystem;
    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $customer;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var Magento\Customer\Api\Data\AddressInterfaceFactory
     */
    protected $addressDataFactory;

    /**
     * @var \Magento\Customer\Api\AddressRepositoryInterface
     */
    protected $addressRepository;
    

    public function __construct(\Magento\Framework\Filesystem $filesystem,\Magento\Customer\Model\CustomerFactory $customer,\Magento\Store\Model\StoreManagerInterface $storeManager,\Magento\Customer\Api\AddressRepositoryInterface $addressRepository,
    \Magento\Customer\Api\Data\AddressInterfaceFactory $addressDataFactory)
    {
        $this->_filesystem = $filesystem;
        $this->customer = $customer;
        $this->_storeManager = $storeManager;
        $this->addressRepository = $addressRepository;
        $this->addressDataFactory = $addressDataFactory;
        parent::__construct();
    }
    /**
     * {@inheritdoc}
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $mediapath = $this->_filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath().'import/';
        $profileName = $input->getArgument(self::FILE_NAME);       
        $source = $input->getOption(self::FILE_SOURCE);       
        $output->writeln("Import from: " . $source);        
        $filePath = ($source=='DIR')? $mediapath:$source;
        foreach($profileName as $profile){
            if($source=='DIR'){
                $filePath = $mediapath.$profile;
            }else{
                $filePath = $profile;
            }
           
            if(!file_exists($filePath)){               
                $output->writeln('<error> Error: File Not Found : '. $profile.' </error>');  
                continue;          

            }
            $output->writeln("File Path: " . $filePath); 
            $fileinfo = pathinfo($filePath);
            if(!isset($fileinfo['extension']) || !in_array($fileinfo['extension'],self::ALLOWED_FILE_EXTENSTIONS)){
                $fileinfo['extension'] =(!isset($fileinfo['extension']))?'None':$fileinfo['extension'];
                $output->writeln('<error> Error: Invalid File Formate : '. $fileinfo['extension'].', Allowd format :'.json_encode(self::ALLOWED_FILE_EXTENSTIONS).' </error>');  
                continue; 
            }
            $import_data=[];
            switch($fileinfo['extension']){
                case 'csv':
                    $import_data = $this->csvToArray($filePath);
                    break;
                case 'json':
                    $import_data = $this->jsonToArray($filePath);
                    break;
                default:
                $output->writeln('<error> Error: Invalid File Formate : '. $fileinfo['extension'].', Allowd format :'.json_encode(self::ALLOWED_FILE_EXTENSTIONS).' </error>');  

            }
            foreach($import_data as $rowData){
                if($this->validateCustomerDataAndSave($rowData,$output)){
                    $output->writeln('<info> Customer Created successfult %s </infor>',$rowData['email']);                    
                }
                echo $this->saveCustomerAddress($rowData,$output);
                if($this->saveCustomerAddress($rowData,$output)){
                    $output->writeln('<info> Customer Address Created successfult %s </info>',$rowData['email']);
                }
                
            } 

        }            
      
    }
    /**
     * customer address row data save
     * @param [] $rowData
     * @param  $output
     * @return bool
     */
    private function saveCustomerAddress($rowData,$output){
        if(isset($rowData['email'])){
            $defaultWebsiteId = $this->_storeManager->getDefaultStoreView()->getWebsiteId();
            $defaultWebsiteId =(isset($rowData['website']) && !$rowData['website']=='')?$rowData['_website']:$defaultWebsiteId;
            $checkCustomer = $this->customer->create()->setWebsiteId($defaultWebsiteId)->loadByEmail($rowData['email']);
            if (!$checkCustomer->hasData()) {
                try{
                    $rowData['customer_id']=$checkCustomer->getId();
                    if (count(array_intersect_key(array_flip(self::REQUIRE_ADDRESS_COLUMN), $rowData)) === count(self::REQUIRE_ADDRESS_COLUMN)) {
                        $address = $this->addressDataFactory->create();
                        $address->setData($rowData);
                        $this->addressRepository->save($address);
                        return true;
                    }                   

                }catch(\Exception $e){
                    $output->writeln($e->getMessage());
                    return false;
                }              

            }
        }
        return false;
    }
    /**
     * customer and address row data save
     * @param [] $rowData
     * @param  $output
     * @return bool
     */
    private function validateCustomerDataAndSave($rowData,$output){
        $defaultWebsiteId = $this->_storeManager->getDefaultStoreView()->getWebsiteId();
        if(isset($rowData['email'])){
            $defaultWebsiteId =(isset($rowData['website']) && !$rowData['website']=='')?$rowData['_website']:$defaultWebsiteId;
            $checkCustomer = $this->customer->create()->setWebsiteId($defaultWebsiteId)->loadByEmail($rowData['email']);
            if (!$checkCustomer->hasData()) {
                try{
                    $rowData['website_id']=$defaultWebsiteId;                   
                    $customer = $this->customer->create();
                    $customer->setData($rowData);
                    $customer->save();
                    
                    if($customer->getId()){                        
                        return true;
                    }else{
                        return false;
                    }

                }catch(\Exception $e){
                    $output->writeln($e->getMessage());
                    return false;
                }
                
            }else{
                $output->writeln('<error> Customer Already Exist : '.$rowData['email'].' </error>');
            }
        }
        return false;
      
    }
    
    /**
     * {@inheritdoc}
     * csv to array convertion
     * @param string $csv_data
     * @return array
     * 
    */
    private function csvToArray($csv_data){
        $csv = Array();
        $rowcount = 0;
        if (($handle = fopen($csv_data, "r")) !== FALSE) {
            $max_line_length = defined('MAX_LINE_LENGTH') ? MAX_LINE_LENGTH : 10000;
            $header = fgetcsv($handle, $max_line_length);
            $header_colcount = count($header);
            while (($row = fgetcsv($handle, $max_line_length)) !== FALSE) {
                $row_colcount = count($row);
                if ($row_colcount == $header_colcount) {
                    $entry = array_combine($header, $row);
                    $csv[] = $entry;
                }
                else {
                    throw new \Exception("csvreader: Invalid number of columns at line " . ($rowcount + 2) . " (row " . ($rowcount + 1) . "). Expected=$header_colcount Got=$row_colcount");                   
                    continue;
                }
                $rowcount++;
            }            
            fclose($handle);
        }
        else {
            throw new \Exception("csvreader: Could not read CSV \"$csv_data\"");         
            
            return null;
        }        
        return $csv;
    }
    /**
     * {@inheritdoc}
     * csv to array convertion
     * @param string $csv_data
     * @return array
    */
    private function jsonToArray($csv_data){
        $json_string = file_get_contents($csv_data);       
        
        if (!empty($json_string)) {
            if(!is_string($json_string) && 
            !is_array(json_decode($json_string, true))){
                throw new \Exception('Invalid Json Format');
                return;
            }
        }else{
            throw new \Exception('Json Data Not Found');
            return;
        }
        $json_conert_data = json_decode($json_string, true);    
        return $json_conert_data;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName("customer:import");
        $this->setDescription("Customer and Address CLI import");
        $this->setDefinition([
            new InputArgument(self::FILE_NAME, InputArgument::REQUIRED | InputArgument::IS_ARRAY, "File Name. Ex:import.csv or impoert.json."),            
            new InputOption(self::FILE_SOURCE, "-s", InputOption::VALUE_REQUIRED, "Impoer File Source. Ex:DIR or URL.",'DIR')
        ]);
        parent::configure();
    }
}

